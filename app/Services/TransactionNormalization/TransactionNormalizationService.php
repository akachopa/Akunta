<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentProcessingJob;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionEvidence;
use App\Services\Ai\ConfidenceEngine;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentProcessing\DocumentStageRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tahap normalisasi transaksi (plan.md §13.1 TRANSACTION GENERATOR, §37 Phase 5).
 *
 * Di sini ketiga acceptance Phase 5 bertemu: satu rekening koran menghasilkan banyak
 * transaksi, satu faktur menghasilkan transaksi beserta buktinya, dan setiap transaksi
 * dapat ditelusuri kembali ke sumbernya lewat `transaction_sources` sampai ke berkas asli.
 *
 * Tiga aturan yang menentukan bentuk service ini:
 *
 * - **Hanya data yang lolos validasi.** Normalizer membaca `document_fields` milik ekstraksi
 *   berstatus `accepted`. Batas ini adalah acceptance Phase 4 "invalid output tidak masuk
 *   transaction pipeline", dan di sinilah ia benar-benar ditegakkan.
 * - **Semua atau tidak sama sekali.** Dokumen yang datanya tidak konsisten menghasilkan nol
 *   transaksi. Rekening koran yang kehilangan satu baris akan membuat kas tidak pernah dapat
 *   direkonsiliasi (plan.md §19.1), dan separuh laporan penjualan menyembunyikan
 *   kekurangannya alih-alih melaporkannya.
 * - **Idempoten, tetapi tidak menimpa keputusan.** Dokumen dapat dinormalisasi ulang setelah
 *   koreksi reviewer atau reprocess. Transaksi lama digantikan, kecuali bila sudah ada yang
 *   disetujui, diposting, atau ditolak — pada keadaan itu normalisasi berhenti alih-alih
 *   membatalkan keputusan manusia atau isi ledger (plan.md §31, §44.6, §44.7).
 */
class TransactionNormalizationService
{
    public function __construct(
        private readonly DocumentStageRecorder $stages,
        private readonly NormalizerRouter $router,
        private readonly TransactionReferenceGenerator $references,
        private readonly ConfidenceEngine $confidence,
        private readonly AuditLogger $audit,
    ) {}

    public function normalize(Document $document): bool
    {
        $document->refresh();

        if ($document->processing_status !== DocumentStatus::Normalizing) {
            return false;
        }

        $job = $this->stages->currentOrOpen($document, DocumentProcessingStage::Normalize);
        $job->markRunning();

        $documentType = $document->document_type;
        $extraction = $document->acceptedExtraction()->with('fields')->first();

        if ($documentType === null || $extraction === null) {
            /*
             * Dokumen mencapai NORMALIZING tanpa ekstraksi yang diterima hanya bila sesuatu
             * memindahkan statusnya di luar pipeline. Menghasilkan transaksi dari keadaan itu
             * berarti menembus batas Phase 4, jadi dokumennya dikembalikan ke manusia.
             */
            $this->needReview(
                $document,
                $job,
                'Dokumen belum memiliki hasil pembacaan yang lolos validasi.',
                'Data dokumen belum dapat dipastikan, sehingga transaksinya belum dapat dibuat.'
            );

            return true;
        }

        $normalizer = $this->router->resolve($documentType);

        if ($normalizer === null) {
            /*
             * Jenis dokumennya terbaca lengkap, hanya belum ada bentuk transaksi yang
             * disepakati untuknya. Dokumen tetap READY karena datanya memang selesai, dan
             * alasannya tercatat pada riwayat tahap agar batas phase terlihat.
             */
            $this->finish($document, $job, [], sprintf(
                'Normalisasi transaksi untuk %s belum tersedia.',
                $documentType->label()
            ));

            return true;
        }

        $result = $normalizer->normalize(ExtractedDocument::for($document, $extraction));

        $existing = Transaction::query()->fromDocument($document)->get();
        $locked = $existing->reject(static fn (Transaction $transaction): bool => $transaction->status->isRegenerable());

        if ($locked->isNotEmpty()) {
            $this->finish($document, $job, [], sprintf(
                'Dokumen sudah memiliki %d transaksi yang disetujui, diposting, atau ditolak, sehingga tidak dinormalisasi ulang.',
                $locked->count()
            ));

            return true;
        }

        if (! $result->isAccepted()) {
            $this->rejected($document, $job, $existing, $result);

            return true;
        }

        $this->write($document, $job, $existing, $result, $extraction->getKey());

        return true;
    }

    /**
     * Menyimpan transaksi, sumber, dan buktinya dalam satu transaksi database.
     *
     * @param  Collection<int, Transaction>  $existing
     */
    private function write(
        Document $document,
        DocumentProcessingJob $job,
        Collection $existing,
        NormalizationResult $result,
        string $extractionId,
    ): void {
        $confidence = $this->documentConfidence($document);

        DB::transaction(function () use ($document, $job, $existing, $result, $extractionId, $confidence): void {
            $this->discard($existing);

            $references = $this->references->nextBatch($document->business, count($result->transactions));
            $created = [];

            foreach ($result->transactions as $position => $normalized) {
                /** @var Transaction $transaction */
                $transaction = Transaction::query()->create([
                    'business_id' => $document->business_id,
                    'reference' => $references[$position],
                    'transaction_date' => $normalized->transactionDate,
                    'description' => $normalized->description,
                    'amount' => $normalized->amount,
                    'direction' => $normalized->direction,
                    'currency' => $normalized->currency,
                    'counterparty_name' => $normalized->counterpartyName,
                    'source_type' => $normalized->sourceType,
                    'status' => $normalized->status,
                    'overall_confidence' => $confidence,
                    'review_reason' => $normalized->reviewReason,
                    'normalized_at' => Carbon::now(),
                ]);

                $transaction->source()->create([
                    'business_id' => $document->business_id,
                    'document_id' => $document->getKey(),
                    'extraction_id' => $extractionId,
                    'source_type' => $normalized->sourceType,
                    'source_reference' => $normalized->sourceReference,
                    'row_index' => $normalized->rowIndex,
                    'page_number' => $normalized->pageNumber,
                ]);

                foreach ($normalized->evidence as $evidence) {
                    TransactionEvidence::query()->create([
                        'business_id' => $document->business_id,
                        'transaction_id' => $transaction->getKey(),
                        'document_id' => $document->getKey(),
                        'type' => $evidence->type,
                        'row_reference' => $evidence->rowReference,
                        'page_number' => $evidence->pageNumber,
                        'field_key' => $evidence->fieldKey?->value,
                        'note' => $evidence->note,
                    ]);
                }

                $created[] = $transaction->reference;
            }

            $document->transitionTo(DocumentStatus::Ready, ['review_reason' => null]);

            $job->markSucceeded([
                'transaction_count' => count($created),
                'references' => array_slice($created, 0, 25),
                'replaced' => $existing->count(),
            ]);
        });

        /*
         * plan.md §31 dan §45.6: pembuatan transaksi adalah operasi sensitif, karena inilah
         * data yang akan menjadi jurnal. Yang dicatat adalah jumlah dan penggantinya, bukan
         * seluruh isi transaksi — rinciannya sudah dapat ditelusuri lewat transaction_sources.
         */
        $this->audit->log(
            action: 'document.normalized',
            entity: $document,
            before: ['transaction_count' => $existing->count()],
            after: ['transaction_count' => count($result->transactions)],
            business: $document->business,
        );
    }

    /**
     * Normalisasi ditolak: dokumen menunggu manusia dan tidak menyisakan transaksi.
     *
     * @param  Collection<int, Transaction>  $existing
     */
    private function rejected(
        Document $document,
        DocumentProcessingJob $job,
        Collection $existing,
        NormalizationResult $result,
    ): void {
        DB::transaction(function () use ($document, $job, $existing, $result): void {
            /*
             * Transaksi lama dibuang meski penolakannya berasal dari data yang baru. Ia
             * berasal dari nilai yang kini dinyatakan tidak konsisten, dan membiarkannya
             * terbaca sebagai data sah berarti sistem menyimpan angka yang ia sendiri
             * tolak. Buktinya tidak hilang: dokumen, ekstraksi, dan field-nya tetap utuh.
             */
            $this->discard($existing);

            $job->markSkipped(implode(' ', $result->errors));

            $document->transitionTo(DocumentStatus::NeedReview, [
                'review_reason' => $this->reviewReason($result->errors),
            ]);
        });
    }

    /**
     * Tahap selesai tanpa menghasilkan transaksi, dan itu bukan kegagalan.
     *
     * @param  array<string, mixed>  $result
     */
    private function finish(
        Document $document,
        DocumentProcessingJob $job,
        array $result,
        string $reason,
    ): void {
        DB::transaction(function () use ($document, $job, $result, $reason): void {
            $job->markSkipped($reason);

            $document->transitionTo(DocumentStatus::Ready, ['review_reason' => null] + $result);
        });
    }

    private function needReview(
        Document $document,
        DocumentProcessingJob $job,
        string $jobReason,
        string $reviewReason,
    ): void {
        DB::transaction(function () use ($document, $job, $jobReason, $reviewReason): void {
            $job->markSkipped($jobReason);

            $document->transitionTo(DocumentStatus::NeedReview, ['review_reason' => $reviewReason]);
        });
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function discard(Collection $transactions): void
    {
        foreach ($transactions as $transaction) {
            /*
             * Dihapus satu per satu, bukan lewat query delete massal, supaya cascade
             * database membersihkan sumber dan buktinya. Bukti transaksi sendiri append-only
             * (plan.md §31): ia hanya boleh hilang bersama transaksi yang dimilikinya, tidak
             * pernah dicabut dari transaksi yang masih hidup.
             */
            $transaction->delete();
        }
    }

    /**
     * Confidence transaksi diwarisi dari dokumen sumbernya (plan.md §15.1).
     *
     * Normalisasi tidak menambah maupun mengurangi kepastian: ia deterministik. Yang
     * menentukan seberapa dapat dipercaya sebuah transaksi adalah seberapa yakin pembacaan
     * dokumennya, dan mata rantai terlemah itulah yang diwariskan.
     */
    private function documentConfidence(Document $document): ?string
    {
        if ($document->classification_confidence === null && $document->extraction_confidence === null) {
            return null;
        }

        return $this->confidence->evaluate([
            'document_type_confidence' => $document->classification_confidence,
            'extraction_confidence' => $document->extraction_confidence,
        ], $document->business)->score;
    }

    /**
     * plan.md §16.3: alasan yang muncul ke user tidak boleh berupa pesan teknis.
     *
     * @param  array<int, string>  $errors
     */
    private function reviewReason(array $errors): string
    {
        return mb_strimwidth(
            'Transaksi belum dapat dibuat dari dokumen ini: ' . ($errors[0] ?? 'data pada dokumen tidak konsisten.'),
            0,
            1000,
            '…'
        );
    }
}

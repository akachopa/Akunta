<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Enums\DocumentPageKind;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Exceptions\DocumentParsingFailed;
use App\Domain\Documents\Exceptions\UnsupportedDocumentFile;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentProcessingJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Ai\AiWorkerClient;
use App\Services\Ai\DocumentParseResult;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menjalankan tahap parse dan mengatur masuk-keluarnya dokumen dari pipeline
 * (plan.md §13.1, §25.1).
 *
 * Tahap klasifikasi dan ekstraksi berada di service-nya sendiri; yang tetap di sini adalah
 * parse beserta pekerjaan yang berlaku untuk seluruh pipeline: memasukkan dokumen ke
 * antrean, memproses ulang, mengarsipkan, dan menutup kegagalan setelah percobaan queue
 * habis.
 *
 * plan.md §40 mewajibkan job yang idempotent dan retry-safe: `process()` menolak
 * mengerjakan dokumen yang statusnya tidak menunggu proses, sehingga job yang terkirim
 * dua kali tidak menghasilkan halaman ganda.
 */
class DocumentProcessingService
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AiWorkerClient $worker,
        private readonly AuditLogger $audit,
        private readonly DocumentStageRecorder $stages,
    ) {}

    /**
     * Menandai dokumen masuk antrean dan mengirim job (plan.md §40 upload async).
     */
    public function queue(Document $document): DocumentProcessingJob
    {
        /*
         * Transisi state machine harus dinilai terhadap baris di database, bukan terhadap
         * salinan model di memori yang bisa saja tertinggal. Status dokumen berubah di
         * queue worker, sehingga instance yang dipegang pemanggil mungkin sudah usang.
         */
        $document->refresh();

        $job = DB::transaction(function () use ($document): DocumentProcessingJob {
            /*
             * Dokumen yang diproses ulang dari arsip otomatis keluar dari arsip: status
             * ARCHIVED dan antrean proses tidak dapat berlaku bersamaan.
             */
            $document->transitionTo(DocumentStatus::Queued, [
                'failed_at' => null,
                'failure_reason' => null,
                'archived_at' => null,
                'archived_by' => null,
            ]);

            return $this->stages->open($document, DocumentProcessingStage::Parse);
        });

        ProcessDocumentJob::dispatch($document->getKey(), $document->business_id);

        return $job;
    }

    /**
     * Memproses ulang dokumen atas permintaan user (plan.md §29.3, §37 Phase 3 retry).
     *
     * Berkas asli tidak pernah dibaca ulang dari user: reprocess memakai berkas yang sama
     * yang sudah tersimpan, sehingga hasil sebelum dan sesudah retry dapat dibandingkan.
     */
    public function reprocess(Document $document, User $actor, ?string $reason = null): DocumentProcessingJob
    {
        $before = $document->processing_status;

        $job = $this->queue($document);

        $this->audit->log(
            action: 'document.reprocessed',
            entity: $document,
            before: ['processing_status' => $before->value],
            after: ['processing_status' => $document->processing_status->value],
            reason: $reason,
            actor: $actor,
        );

        return $job;
    }

    /**
     * plan.md §29.3: archive dokumen.
     *
     * Arsip tidak menghapus berkas maupun halaman. plan.md §44.7 melarang penghapusan
     * jejak, dan dokumen yang sudah menjadi evidence journal harus tetap dapat dibuka.
     */
    public function archive(Document $document, User $actor, ?string $reason = null): Document
    {
        $before = $document->processing_status;

        $document->transitionTo(DocumentStatus::Archived, [
            'archived_at' => Carbon::now(),
            'archived_by' => $actor->getKey(),
        ]);

        $this->audit->log(
            action: 'document.archived',
            entity: $document,
            before: ['processing_status' => $before->value],
            after: ['processing_status' => DocumentStatus::Archived->value],
            reason: $reason,
            actor: $actor,
        );

        return $document;
    }

    /**
     * Menjalankan tahap parse untuk satu dokumen.
     *
     * Dipanggil dari queue worker, bukan dari request. Mengembalikan false bila dokumen
     * memang tidak perlu diproses, misalnya karena job terkirim dua kali atau karena
     * dokumen sudah diarsipkan sejak job dikirim.
     */
    public function process(Document $document): bool
    {
        if (! $this->shouldProcess($document)) {
            return false;
        }

        $job = $this->stages->currentOrOpen($document, DocumentProcessingStage::Parse);

        $document->transitionTo(DocumentStatus::Parsing);
        $job->markRunning();

        try {
            $result = $this->parse($document);
        } catch (UnsupportedDocumentFile $exception) {
            $this->markUnsupported($document, $job, $exception);

            return true;
        } catch (Throwable $exception) {
            /*
             * Percobaan yang gagal dicatat, tetapi status dokumen dibiarkan PARSING dan
             * exception-nya dilempar ulang supaya queue mencoba lagi dengan backoff.
             * FAILED baru ditetapkan setelah seluruh percobaan habis, di
             * markPermanentFailure(), agar user tidak melihat dokumen ditandai gagal
             * padahal masih akan dicoba otomatis.
             */
            $job->markFailed($exception);

            throw $exception;
        }

        DB::transaction(function () use ($document, $job, $result): void {
            $this->storePages($document, $result);

            $document->transitionTo(DocumentStatus::Classifying, [
                'page_count' => $result->pageCount,
                'content_kind' => $result->contentKind,
                'needs_ocr' => $result->needsOcr,
                'parsed_at' => Carbon::now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

            $job->markSucceeded($result->toJobResult());

            $this->stages->recordPendingStages($document);
        });

        return true;
    }

    /**
     * Mencatat kegagalan permanen setelah seluruh percobaan queue habis.
     *
     * Tahap yang sedang berjalan diturunkan dari status dokumen, bukan diterima sebagai
     * argumen, karena callback `failed()` sebuah queue job tidak selalu mengetahui tahap
     * mana yang gagal — job klasifikasi dapat gagal setelah dokumen berpindah ke tahap
     * berikutnya pada percobaan sebelumnya.
     */
    public function markPermanentFailure(Document $document, Throwable $exception): void
    {
        $stage = $this->stages->stageFor($document->processing_status);

        $job = $stage === null ? null : $this->stages->current($document, $stage);

        if ($job !== null && ! $job->status->isFinished()) {
            $job->markFailed($exception);
        }

        if ($document->processing_status->canTransitionTo(DocumentStatus::Failed)) {
            $document->transitionTo(DocumentStatus::Failed, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $this->failureReason($exception),
            ]);
        }
    }

    /**
     * Status yang menandakan dokumen menunggu atau sedang dalam tahap parse.
     *
     * PARSING ikut diterima supaya percobaan yang terputus, misalnya karena worker mati
     * di tengah jalan, dapat dilanjutkan tanpa intervensi manual.
     */
    private function shouldProcess(Document $document): bool
    {
        return in_array(
            $document->processing_status,
            [DocumentStatus::Queued, DocumentStatus::Parsing],
            true
        );
    }

    private function parse(Document $document): DocumentParseResult
    {
        $file = $document->originalFile;

        if ($file === null) {
            throw DocumentParsingFailed::missingOriginalFile();
        }

        return $this->worker->parseDocument(
            $this->storage->read($file),
            $document->original_filename,
            $document->mime_type,
        );
    }

    /**
     * Halaman hasil parse ditulis ulang setiap percobaan.
     *
     * Halaman adalah data turunan, jadi menggantinya aman dan justru diperlukan agar
     * retry tidak menghasilkan halaman ganda. Berkas aslinya sendiri tidak pernah
     * disentuh (plan.md §37 Phase 3).
     */
    private function storePages(Document $document, DocumentParseResult $result): void
    {
        $document->pages()->delete();

        foreach ($result->pages as $page) {
            $text = is_string($page['text'] ?? null) ? $page['text'] : null;
            $rows = is_array($page['rows'] ?? null) ? $page['rows'] : null;

            $document->pages()->create([
                'business_id' => $document->business_id,
                'page_number' => (int) ($page['page_number'] ?? 1),
                'kind' => $this->resolvePageKind($page['kind'] ?? null),
                'label' => is_string($page['label'] ?? null) ? $page['label'] : null,
                'text' => $text,
                'char_count' => $text === null ? 0 : mb_strlen($text),
                'rows' => $rows,
                'row_count' => $rows === null ? null : count($rows),
                'needs_ocr' => (bool) ($page['needs_ocr'] ?? false),
                'metadata' => is_array($page['metadata'] ?? null) ? $page['metadata'] : null,
            ]);
        }
    }

    private function resolvePageKind(mixed $kind): DocumentPageKind
    {
        return is_string($kind)
            ? (DocumentPageKind::tryFrom($kind) ?? DocumentPageKind::Table)
            : DocumentPageKind::Table;
    }

    private function markUnsupported(Document $document, DocumentProcessingJob $job, UnsupportedDocumentFile $exception): void
    {
        DB::transaction(function () use ($document, $job, $exception): void {
            /*
             * Berkas tanpa parser dicatat sebagai skipped, bukan failed: tidak ada yang
             * salah pada sistem, dan mengulang dengan berkas yang sama tidak akan
             * mengubah hasilnya.
             */
            $job->markSkipped($exception->getMessage());

            $document->transitionTo(DocumentStatus::Unsupported, [
                'failed_at' => Carbon::now(),
                'failure_reason' => $exception->getMessage(),
            ]);
        });
    }

    private function failureReason(Throwable $exception): string
    {
        // plan.md §30: log dan pesan tidak boleh membocorkan isi dokumen, jadi hanya
        // pesan exception yang dipotong yang disimpan.
        return mb_strimwidth($exception->getMessage(), 0, 1000, '…');
    }
}

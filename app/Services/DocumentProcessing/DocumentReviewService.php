<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiFeedback;
use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentFieldSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\DocumentTypeSource;
use App\Domain\Documents\Exceptions\DocumentReviewRejected;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentField;
use App\Jobs\ExtractDocumentJob;
use App\Jobs\NormalizeDocumentJob;
use App\Models\User;
use App\Services\Ai\AiPredictionRecorder;
use App\Services\Ai\ConfidenceEngine;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Review dokumen oleh manusia (plan.md §16, §17.1, §37 Phase 4).
 *
 * Tiga tindakan yang berbeda maksudnya, dan pemisahannya penting:
 *
 * - **Konfirmasi jenis dokumen.** Membuka kembali tahap ekstraksi, karena schema yang
 *   dipakai berubah. Prediksi AI tidak dihapus, hanya dikalahkan.
 * - **Koreksi field.** Menetapkan nilai yang benar beserta kepastiannya. Field yang
 *   disentuh reviewer bernilai pasti; field yang tidak disentuh tetap membawa confidence
 *   AI-nya, sehingga dokumen tidak lolos otomatis hanya karena sebagian datanya diperiksa.
 * - **Persetujuan dokumen.** Pernyataan manusia bahwa dokumennya benar. Inilah satu-satunya
 *   jalan sebuah dokumen mencapai READY tanpa melewati ambang confidence.
 *
 * Setiap koreksi tersimpan pada `ai_feedback`. plan.md §17 mewajibkannya, dan itulah yang
 * membedakan review yang hanya membetulkan satu dokumen dari review yang menghasilkan bahan
 * pengukuran akurasi (plan.md §32.2). Learning layer §17.2 sendiri belum dibangun: tidak
 * ada kode yang membaca tabel itu sebagai masukan prediksi berikutnya.
 */
class DocumentReviewService
{
    public function __construct(
        private readonly AiPredictionRecorder $predictions,
        private readonly ConfidenceEngine $confidence,
        private readonly DocumentFieldWriter $fields,
        private readonly ExtractionValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reviewer menetapkan jenis dokumen (plan.md §17.1).
     */
    public function confirmDocumentType(
        Document $document,
        DocumentType $documentType,
        User $actor,
        ?string $reason = null,
    ): Document {
        $this->assertReviewable($document);

        $previousType = $document->document_type;
        $previousSource = $document->document_type_source;
        $prediction = $this->appliedPrediction($document);

        DB::transaction(function () use ($document, $documentType, $previousType, $prediction, $actor, $reason): void {
            $this->predictions->supersede($document, AiTask::ClassifyDocument);

            /*
             * Feedback hanya dicatat ketika nilainya benar-benar berubah. Koreksi yang tidak
             * mengubah apa pun bukan koreksi, dan constraint `ai_feedback_changes_something`
             * menolaknya. Konfirmasi atas prediksi yang sudah benar tetap terekam, yaitu
             * pada `document_type_source` dan audit log.
             */
            if ($previousType !== $documentType) {
                $this->recordFeedback(
                    document: $document,
                    prediction: $prediction,
                    task: AiTask::ClassifyDocument,
                    fieldKey: null,
                    originalValue: $previousType?->value,
                    originalConfidence: $document->classification_confidence,
                    finalValue: $documentType->value,
                    documentType: $documentType,
                    actor: $actor,
                    reason: $reason,
                );
            }

            $document->document_type = $documentType;
            $document->document_type_source = DocumentTypeSource::Review;

            // Jenis dokumen yang dinyatakan manusia tidak lagi membawa ketidakpastian.
            $document->classification_confidence = '1.0000';
            $document->reviewed_at = Carbon::now();
            $document->reviewed_by = $actor->getKey();

            if ($previousType === $documentType) {
                $document->save();

                return;
            }

            /*
             * Jenis dokumen berubah, sehingga schema ekstraksinya berubah pula. Hasil
             * ekstraksi lama tidak dihapus — ia tetap menjadi bukti percobaan sebelumnya —
             * tetapi dokumen harus diekstraksi ulang sebelum datanya boleh dipakai.
             */
            $document->transitionTo(DocumentStatus::Extracting, [
                'review_reason' => 'Dokumen sedang dibaca ulang memakai jenis dokumen yang Anda pilih.',
            ]);
        });

        $this->audit->log(
            action: 'document.type_confirmed',
            entity: $document,
            before: [
                'document_type' => $previousType?->value,
                'document_type_source' => $previousSource?->value,
            ],
            after: [
                'document_type' => $documentType->value,
                'document_type_source' => DocumentTypeSource::Review->value,
            ],
            reason: $reason,
            actor: $actor,
        );

        if ($previousType !== $documentType) {
            ExtractDocumentJob::dispatch($document->getKey(), $document->business_id);
        }

        return $document;
    }

    /**
     * Reviewer memastikan nilai field, dengan atau tanpa mengubahnya (plan.md §16.2).
     *
     * Setiap entri berarti "saya sudah memeriksa field ini". Nilai yang berubah tersimpan
     * sebagai koreksi manusia; nilai yang sama tetap ditandai terkonfirmasi. Keduanya
     * bernilai pasti, tetapi hanya yang berubah menjadi sinyal akurasi.
     *
     * @param  array<string, string|null>  $values
     */
    public function confirmFields(
        Document $document,
        array $values,
        User $actor,
        ?string $reason = null,
    ): Document {
        $this->assertReviewable($document);

        $extraction = $document->acceptedExtraction;

        if ($extraction === null) {
            throw DocumentReviewRejected::withoutAcceptedExtraction($document);
        }

        $before = [];
        $after = [];

        DB::transaction(function () use ($document, $extraction, $values, $actor, $reason, &$before, &$after): void {
            foreach ($values as $rawKey => $rawValue) {
                $key = DocumentFieldKey::tryFrom($rawKey);

                /*
                 * Baris mutasi tidak dikoreksi lewat jalur ini. Rekening koran dapat memuat
                 * ratusan baris, dan koreksinya adalah pekerjaan reconciliation pada
                 * Phase 7, bukan formulir field dokumen.
                 */
                if ($key === null || $key->isRowField()) {
                    throw DocumentReviewRejected::unrecognizedField($rawKey);
                }

                $field = $extraction->fields()
                    ->documentLevel()
                    ->where('field_key', $key->value)
                    ->first();

                if (! $field instanceof DocumentField) {
                    throw DocumentReviewRejected::unknownField($key);
                }

                $original = $field->displayValue();
                $originalConfidence = (string) $field->confidence;

                $final = $this->applyCorrection($field, $key, $rawValue, $actor);

                if ($original === $final) {
                    continue;
                }

                $before[$key->value] = $original;
                $after[$key->value] = $final;

                $this->recordFeedback(
                    document: $document,
                    prediction: null,
                    task: AiTask::ExtractDocument,
                    fieldKey: $key,
                    originalValue: $original,
                    originalConfidence: $originalConfidence,
                    finalValue: $final,
                    documentType: $document->document_type,
                    actor: $actor,
                    reason: $reason,
                );
            }

            $this->refreshAfterReview($document, $actor);
        });

        if ($before !== []) {
            $this->audit->log(
                action: 'document.fields_corrected',
                entity: $document,
                before: $before,
                after: $after,
                reason: $reason,
                actor: $actor,
            );
        }

        /*
         * Koreksi yang membuat dokumen melewati ambang confidence langsung melanjutkan
         * pipeline: nilai yang berlaku sudah berubah, dan transaksinya harus dibentuk dari
         * nilai itu, bukan dari nilai yang dikoreksi.
         */
        if ($document->processing_status === DocumentStatus::Normalizing) {
            NormalizeDocumentJob::dispatch($document->getKey(), $document->business_id);
        }

        return $document;
    }

    /**
     * Reviewer menyatakan dokumennya benar (plan.md §16.1).
     *
     * Satu-satunya jalan dokumen melewati ambang confidence tanpa memenuhinya. Syaratnya
     * tetap ada ekstraksi yang lolos validasi: persetujuan manusia tidak dapat menciptakan
     * data yang tidak pernah terbaca (plan.md §37 Phase 4).
     *
     * Sejak Phase 5, persetujuan mengantar dokumen ke NORMALIZING, bukan langsung READY.
     * Pernyataan manusia berlaku atas data dokumennya, dan transaksinya tetap harus dibentuk
     * dari data itu — termasuk bila pembentukannya justru menemukan ketidakkonsistenan yang
     * belum terlihat pada tampilan field.
     */
    public function approve(Document $document, User $actor, ?string $reason = null): Document
    {
        $this->assertReviewable($document);

        if ($document->acceptedExtraction === null) {
            throw DocumentReviewRejected::withoutAcceptedExtraction($document);
        }

        $before = $document->processing_status;

        $document->transitionTo(DocumentStatus::Normalizing, [
            'reviewed_at' => Carbon::now(),
            'reviewed_by' => $actor->getKey(),
            'review_reason' => null,
        ]);

        $this->audit->log(
            action: 'document.review_approved',
            entity: $document,
            before: ['processing_status' => $before->value],
            after: ['processing_status' => DocumentStatus::Normalizing->value],
            reason: $reason,
            actor: $actor,
        );

        NormalizeDocumentJob::dispatch($document->getKey(), $document->business_id);

        return $document;
    }

    /**
     * Menerapkan satu koreksi pada barisnya.
     *
     * Bukti asal nilai — halaman dan cuplikan teks — tidak dihapus meski nilainya berubah.
     * plan.md §45.12 menuntut traceability tetap utuh, dan reviewer berikutnya perlu tahu
     * dari mana angka yang dikoreksi itu semula terbaca.
     */
    private function applyCorrection(
        DocumentField $field,
        DocumentFieldKey $key,
        ?string $rawValue,
        User $actor,
    ): ?string {
        if ($rawValue !== null && trim($rawValue) !== '') {
            $prepared = $this->validator->prepareCorrection($key, $rawValue);

            if ($prepared === null) {
                throw DocumentReviewRejected::invalidValue($key);
            }

            $changed = $prepared->value() !== $field->displayValue();

            $field->value_text = $prepared->valueText;
            $field->value_number = $prepared->valueNumber;
            $field->value_date = $prepared->valueDate === null
                ? null
                : Carbon::createFromFormat('!Y-m-d', $prepared->valueDate);

            /*
             * `source` menjadi `review` hanya ketika nilainya benar-benar berubah. Nilai
             * yang dikonfirmasi tanpa perubahan tetap berasal dari AI, dan membedakan
             * keduanya adalah selisih antara "AI benar" dan "AI salah lalu dibetulkan" —
             * dua hal yang tidak boleh tercampur pada pengukuran akurasi (plan.md §32.2).
             */
            if ($changed) {
                $field->source = DocumentFieldSource::Review;
            }
        }

        $field->confidence = '1.0000';
        $field->is_confirmed = true;
        $field->confirmed_by = $actor->getKey();
        $field->confirmed_at = Carbon::now();
        $field->save();

        return $field->displayValue();
    }

    /**
     * Menghitung ulang proyeksi canonical dan confidence setelah review.
     *
     * Confidence ekstraksi diturunkan dari field, bukan dari angka yang dikirim provider,
     * karena setelah review angka provider tidak lagi mewakili keadaan: sebagian field kini
     * pasti. Yang dipakai adalah confidence terendah di antara field tingkat dokumen —
     * aturan mata rantai terlemah yang sama dengan ConfidenceEngine (plan.md §15.1) — supaya
     * satu field yang masih kabur tetap menahan dokumen di antrean review meski field
     * lainnya sudah diperiksa.
     */
    private function refreshAfterReview(Document $document, User $actor): void
    {
        $fields = $document->fields()->documentLevel()->get();

        $lowest = null;
        $prepared = [];

        foreach ($fields as $field) {
            $confidence = $this->confidence->normalize((string) $field->confidence);

            if ($lowest === null || bccomp($confidence, $lowest, 4) < 0) {
                $lowest = $confidence;
            }

            $prepared[$field->field_key->value] = new PreparedField(
                key: $field->field_key,
                kind: $field->kind,
                valueText: $field->value_text,
                valueNumber: $field->value_number,
                valueDate: $field->value_date?->toDateString(),
                confidence: $confidence,
                pageNumber: $field->page_number,
                sourceText: $field->source_text,
                rowIndex: null,
            );
        }

        $assessment = $this->confidence->evaluate([
            'document_type_confidence' => $document->classification_confidence,
            'extraction_confidence' => $lowest,
        ], $document->business);

        $document->transitionTo(
            $assessment->band->nextDocumentStatus(),
            $this->fields->canonicalAttributes($prepared) + [
                'extraction_confidence' => $lowest,
                'reviewed_at' => Carbon::now(),
                'reviewed_by' => $actor->getKey(),
                'review_reason' => $assessment->reviewReason(),
            ]
        );
    }

    /**
     * Prediksi jenis dokumen yang sedang dipakai, sebagai pembanding koreksi.
     */
    private function appliedPrediction(Document $document): ?AiPrediction
    {
        $prediction = $document->predictions()
            ->where('task', AiTask::ClassifyDocument->value)
            ->where('status', AiPredictionStatus::Applied->value)
            ->first();

        return $prediction instanceof AiPrediction ? $prediction : null;
    }

    private function recordFeedback(
        Document $document,
        ?AiPrediction $prediction,
        AiTask $task,
        ?DocumentFieldKey $fieldKey,
        ?string $originalValue,
        ?string $originalConfidence,
        ?string $finalValue,
        ?DocumentType $documentType,
        User $actor,
        ?string $reason,
    ): void {
        AiFeedback::query()->create([
            'business_id' => $document->business_id,
            'document_id' => $document->getKey(),
            'prediction_id' => $prediction?->getKey(),
            'task' => $task,
            'field_key' => $fieldKey?->value,
            'original_value' => $originalValue,
            'original_confidence' => $originalConfidence,
            'final_value' => $finalValue,
            'document_type' => $documentType,
            'reviewer_id' => $actor->getKey(),
            'reason' => $reason,
        ]);
    }

    /**
     * Dokumen yang masih berjalan di pipeline tidak boleh direview.
     *
     * Bukan kehati-hatian berlebihan: tahap yang sedang berjalan akan menulis ulang field
     * dan status dokumen begitu selesai, sehingga koreksi yang masuk di tengahnya akan
     * hilang tanpa jejak — dan reviewer tidak akan tahu koreksinya tidak berlaku.
     */
    private function assertReviewable(Document $document): void
    {
        $reviewable = in_array(
            $document->processing_status,
            [DocumentStatus::NeedReview, DocumentStatus::Ready],
            true
        );

        if (! $reviewable) {
            throw DocumentReviewRejected::notReviewable($document);
        }
    }
}

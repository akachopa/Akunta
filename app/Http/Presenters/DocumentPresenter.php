<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Ai\Models\AiPredictionCandidate;
use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Models\DocumentField;
use App\Domain\Documents\Models\DocumentPage;
use App\Domain\Documents\Models\DocumentProcessingJob;
use App\Services\Ai\ConfidenceEngine;

/**
 * Bentuk payload dokumen yang dipakai bersama oleh web (Inertia) dan API v1.
 *
 * Disatukan agar status pipeline yang dilihat user di inbox identik dengan yang dibaca
 * klien API, sehingga tidak ada dua definisi "status dokumen" yang bisa menyimpang.
 *
 * Tidak ada method di sini yang menghasilkan URL berkas. Berkas hanya dapat diakses lewat
 * route unduh terautentikasi (plan.md §30, §44.11).
 */
class DocumentPresenter
{
    public function __construct(private readonly ConfidenceEngine $confidence) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Document $document): array
    {
        return [
            'id' => $document->getKey(),
            'reference' => $document->reference,
            'original_filename' => $document->original_filename,
            'file_extension' => $document->file_extension,
            'mime_type' => $document->mime_type,
            'byte_size' => $document->byte_size,
            'source_type' => $document->source_type->value,
            'processing_status' => $document->processing_status->value,
            'processing_status_label' => $document->processing_status->label(),
            'is_processing' => $document->processing_status->isProcessing(),
            'needs_attention' => $document->processing_status->needsAttention(),
            'is_retryable' => $document->processing_status->isRetryable(),
            'document_type' => $document->document_type?->value,
            'document_type_label' => $document->document_type?->label(),
            'document_type_source' => $document->document_type_source?->value,
            'document_type_source_label' => $document->document_type_source?->label(),
            'page_count' => $document->page_count,
            'content_kind' => $document->content_kind,
            'needs_ocr' => $document->needs_ocr,

            /*
             * Proyeksi canonical plan.md §8.1. Nilai uang tetap string: nilainya diteruskan
             * apa adanya ke klien, dan mengubahnya menjadi number JSON akan mengembalikan
             * float yang justru dihindari plan.md §44.14.
             */
            'document_date' => $document->document_date?->toDateString(),
            'currency' => $document->currency,
            'subtotal' => $document->subtotal,
            'tax' => $document->tax,
            'total' => $document->total,

            'classification_confidence' => $document->classification_confidence,
            'extraction_confidence' => $document->extraction_confidence,
            'confidence' => $this->confidenceAssessment($document),
            'review_reason' => $document->review_reason,

            'uploaded_at' => $document->uploaded_at->toIso8601String(),
            'parsed_at' => $document->parsed_at?->toIso8601String(),
            'classified_at' => $document->classified_at?->toIso8601String(),
            'extracted_at' => $document->extracted_at?->toIso8601String(),
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'failure_reason' => $document->failure_reason,
            'archived_at' => $document->archived_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Document $document): array
    {
        return $this->summary($document) + [
            'checksum_sha256' => $document->checksum_sha256,
            'uploaded_by' => $document->uploader?->name,
            'archived_by' => $document->archiver?->name,
            'reviewed_by' => $document->reviewer?->name,
            'pages' => $document->pages->map(fn (DocumentPage $page): array => $this->page($page))->all(),

            /*
             * Field, baris, dan riwayat ekstraksi dikirim bersama detail dokumen karena UI
             * review memerlukan ketiganya sekaligus: nilai yang harus diperiksa, bukti
             * asalnya, dan alasan percobaan sebelumnya ditolak (plan.md §16.2, §45.10).
             */
            'fields' => $document->fields
                ->filter(static fn (DocumentField $field): bool => $field->row_index === null)
                ->sortBy(fn (DocumentField $field): int => $this->fieldPosition($field))
                ->values()
                ->map(fn (DocumentField $field): array => $this->field($field))
                ->all(),
            'rows' => $this->rows($document),
            'extractions' => $document->extractions
                ->map(fn (DocumentExtraction $extraction): array => $this->extraction($extraction))
                ->all(),
            'classification' => $this->classification($document),
            'documentTypes' => array_map(
                static fn (DocumentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                array_values(DocumentType::selectableOnUpload())
            ),

            /*
             * Timeline diurutkan mengikuti urutan pipeline plan.md §13.1, bukan waktu
             * pembuatan baris: tahap yang belum dibangun dibuat dalam transaksi yang sama
             * sehingga timestamp-nya tidak dapat membedakan urutan.
             */
            'processing_jobs' => $document->processingJobs
                ->sortBy(fn (DocumentProcessingJob $job): int => $this->stagePosition($job) * 10_000 + $job->attempt)
                ->values()
                ->map(fn (DocumentProcessingJob $job): array => $this->job($job))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(DocumentPage $page): array
    {
        return [
            'id' => $page->getKey(),
            'page_number' => $page->page_number,
            'kind' => $page->kind->value,
            'label' => $page->label,
            'is_tabular' => $page->isTabular(),
            'text' => $page->text,
            'char_count' => $page->char_count,
            'rows' => $page->rows,
            'row_count' => $page->row_count,
            'needs_ocr' => $page->needs_ocr,
            'metadata' => $page->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function job(DocumentProcessingJob $job): array
    {
        return [
            'id' => $job->getKey(),
            'stage' => $job->stage->value,
            'stage_label' => $job->stage->label(),
            'stage_phase' => $job->stage->phase(),
            'stage_implemented' => $job->stage->isImplemented(),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'attempt' => $job->attempt,
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'duration_ms' => $job->duration_ms,
            'error_message' => $job->error_message,
            'result' => $job->result,
        ];
    }

    /**
     * Satu nilai hasil ekstraksi beserta buktinya (plan.md §45.10).
     *
     * @return array<string, mixed>
     */
    public function field(DocumentField $field): array
    {
        return [
            'id' => $field->getKey(),
            'key' => $field->field_key->value,
            'label' => $field->field_key->label(),
            'kind' => $field->kind->value,
            'value' => $field->displayValue(),
            'confidence' => $field->confidence,
            'source' => $field->source->value,
            'source_label' => $field->source->label(),
            'page_number' => $field->page_number,
            'source_text' => $field->source_text,
            'is_confirmed' => $field->is_confirmed,
            'confirmed_by' => $field->confirmer?->name,
            'confirmed_at' => $field->confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function extraction(DocumentExtraction $extraction): array
    {
        return [
            'id' => $extraction->getKey(),
            'attempt' => $extraction->attempt,
            'document_type' => $extraction->document_type->value,
            'document_type_label' => $extraction->document_type->label(),
            'extractor' => $extraction->extractor,
            'schema_version' => $extraction->schema_version,
            'status' => $extraction->status->value,
            'status_label' => $extraction->status->label(),
            'confidence' => $extraction->confidence,
            'validation_errors' => $extraction->validation_errors ?? [],
            'created_at' => $extraction->created_at->toIso8601String(),
        ];
    }

    /**
     * Pita confidence dokumen (plan.md §15.2).
     *
     * Dihitung ulang di sini, bukan disimpan sebagai kolom, karena ambangnya dapat diubah
     * per bisnis: pita yang tersimpan akan menjadi usang begitu ambangnya diatur, dan
     * dokumen akan menampilkan keputusan yang tidak lagi berlaku.
     *
     * @return array<string, mixed>|null
     */
    private function confidenceAssessment(Document $document): ?array
    {
        if ($document->classification_confidence === null && $document->extraction_confidence === null) {
            return null;
        }

        return $this->confidence->evaluate([
            'document_type_confidence' => $document->classification_confidence,
            'extraction_confidence' => $document->extraction_confidence,
        ], $document->business)->toArray();
    }

    /**
     * Prediksi jenis dokumen terakhir beserta alternatifnya (plan.md §14.1).
     *
     * @return array<string, mixed>|null
     */
    private function classification(Document $document): ?array
    {
        $prediction = $document->predictions->first();

        if (! $prediction instanceof AiPrediction) {
            return null;
        }

        $type = DocumentType::tryFrom($prediction->predicted_value);

        return [
            'predicted_value' => $prediction->predicted_value,
            'predicted_label' => $type?->label() ?? $prediction->predicted_value,
            'confidence' => $prediction->confidence,
            'reason' => $prediction->reason,
            'status' => $prediction->status->value,
            'status_label' => $prediction->status->label(),
            'created_at' => $prediction->created_at->toIso8601String(),
            'candidates' => $prediction->candidates
                ->map(static function (AiPredictionCandidate $candidate): array {
                    $candidateType = DocumentType::tryFrom($candidate->candidate_value);

                    return [
                        'value' => $candidate->candidate_value,
                        'label' => $candidateType?->label() ?? $candidate->candidate_value,
                        'confidence' => $candidate->confidence,
                        'rank' => $candidate->rank,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Baris mutasi, dikelompokkan per `row_index` (plan.md §8.4 `row_reference`).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(Document $document): array
    {
        $grouped = $document->fields
            ->filter(static fn (DocumentField $field): bool => $field->row_index !== null)
            ->groupBy(static fn (DocumentField $field): int => (int) $field->row_index)
            ->sortKeys();

        $rows = [];

        foreach ($grouped as $index => $fields) {
            $values = [];

            foreach ($fields as $field) {
                $values[$field->field_key->value] = $field->displayValue();
            }

            $rows[] = [
                'row_index' => (int) $index,
                'page_number' => $fields->first()?->page_number,
                'values' => $values,
            ];
        }

        return $rows;
    }

    private function fieldPosition(DocumentField $field): int
    {
        $position = array_search($field->field_key, DocumentFieldKey::cases(), true);

        return $position === false ? count(DocumentFieldKey::cases()) : $position;
    }

    private function stagePosition(DocumentProcessingJob $job): int
    {
        $position = array_search($job->stage, DocumentProcessingStage::cases(), true);

        return $position === false ? count(DocumentProcessingStage::cases()) : $position;
    }
}

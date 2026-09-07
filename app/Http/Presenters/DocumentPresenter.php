<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPage;
use App\Domain\Documents\Models\DocumentProcessingJob;

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
            'document_type_source' => $document->document_type_source,
            'page_count' => $document->page_count,
            'content_kind' => $document->content_kind,
            'needs_ocr' => $document->needs_ocr,
            'uploaded_at' => $document->uploaded_at->toIso8601String(),
            'parsed_at' => $document->parsed_at?->toIso8601String(),
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
            'pages' => $document->pages->map(fn (DocumentPage $page): array => $this->page($page))->all(),

            /*
             * Timeline diurutkan mengikuti urutan pipeline plan.md §13.1, bukan waktu
             * pembuatan baris: tahap yang belum dibangun dibuat dalam transaksi yang sama
             * sehingga timestamp-nya tidak dapat membedakan urutan.
             */
            'processing_jobs' => $document->processingJobs
                ->sortBy([
                    fn (DocumentProcessingJob $job): int => $this->stagePosition($job),
                    fn (DocumentProcessingJob $job): int => $job->attempt,
                ])
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

    private function stagePosition(DocumentProcessingJob $job): int
    {
        $position = array_search($job->stage, DocumentProcessingStage::cases(), true);

        return $position === false ? PHP_INT_MAX : $position;
    }
}

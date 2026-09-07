<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\ProcessingJobStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentProcessingJob;
use Illuminate\Support\Carbon;

/**
 * Pencatat riwayat tahap pipeline (plan.md §32.1).
 *
 * Dipisahkan dari service tahap karena ketiga tahap yang sudah dibangun — parse, classify,
 * extract — memerlukan pembukuan yang sama persis: satu baris per percobaan, percobaan lama
 * tidak ditimpa, dan tahap yang belum dibangun tercatat sebagai menunggu. Menyalin logika
 * itu ke setiap service akan membuat riwayat antar tahap tidak konsisten, dan riwayat
 * itulah sumber `average_processing_time` serta `documents_failed`.
 */
class DocumentStageRecorder
{
    /**
     * Membuka percobaan baru untuk satu tahap.
     *
     * Percobaan sebelumnya tidak ditimpa karena plan.md §32.1 memerlukan riwayat durasi dan
     * kegagalan, dan user perlu melihat penyebab kegagalan sebelumnya.
     */
    public function open(Document $document, DocumentProcessingStage $stage): DocumentProcessingJob
    {
        $attempt = (int) DocumentProcessingJob::query()
            ->where('document_id', $document->getKey())
            ->forStage($stage)
            ->max('attempt');

        /** @var DocumentProcessingJob $job */
        $job = $document->processingJobs()->create([
            'business_id' => $document->business_id,
            'stage' => $stage,
            'status' => ProcessingJobStatus::Queued,
            'attempt' => $attempt + 1,
            'queued_at' => Carbon::now(),
        ]);

        return $job;
    }

    public function current(Document $document, DocumentProcessingStage $stage): ?DocumentProcessingJob
    {
        return DocumentProcessingJob::query()
            ->where('document_id', $document->getKey())
            ->forStage($stage)
            ->orderByDesc('attempt')
            ->first();
    }

    /**
     * Percobaan yang sedang berjalan, atau percobaan baru bila belum ada yang terbuka.
     */
    public function currentOrOpen(Document $document, DocumentProcessingStage $stage): DocumentProcessingJob
    {
        $job = $this->current($document, $stage);

        if ($job === null || $job->status->isFinished()) {
            return $this->open($document, $stage);
        }

        return $job;
    }

    /**
     * Mencatat tahap yang phase-nya belum dibangun sebagai `pending`.
     *
     * Baris ini membuat batas phase terlihat di UI: user melihat dokumennya sudah terbaca
     * dan sedang menunggu tahap berikutnya, bukan mengira prosesnya menggantung.
     */
    public function recordPendingStages(Document $document): void
    {
        foreach (DocumentProcessingStage::cases() as $stage) {
            if ($stage->isImplemented()) {
                continue;
            }

            if ($this->current($document, $stage) !== null) {
                continue;
            }

            $document->processingJobs()->create([
                'business_id' => $document->business_id,
                'stage' => $stage,
                'status' => ProcessingJobStatus::Pending,
                'attempt' => 1,
                'error_message' => sprintf('Tahap %s dibangun pada Phase %d.', $stage->label(), $stage->phase()),
            ]);
        }
    }

    /**
     * Tahap yang sedang dikerjakan dokumen, diturunkan dari statusnya.
     *
     * Dipakai ketika penanganan kegagalan tidak tahu tahap mana yang sedang berjalan,
     * misalnya pada callback `failed()` sebuah queue job.
     */
    public function stageFor(DocumentStatus $status): ?DocumentProcessingStage
    {
        foreach (DocumentProcessingStage::cases() as $stage) {
            if ($stage->runningStatus() === $status) {
                return $stage;
            }
        }

        return null;
    }
}

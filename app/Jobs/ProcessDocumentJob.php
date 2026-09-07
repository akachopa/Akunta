<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Services\DocumentProcessing\DocumentProcessingService;

/**
 * Tahap parse (plan.md §13.1).
 *
 * Tahap berikutnya dikirim dari sini, bukan dari dalam service, supaya pemesanan pekerjaan
 * antar tahap terlihat pada satu lapisan: service memutuskan hasil sebuah tahap, dan job
 * memutuskan apa yang dikerjakan setelahnya. Pengiriman dijaga oleh status dokumen, jadi
 * dokumen yang berakhir di UNSUPPORTED atau NEED_REVIEW tidak melanjutkan pipeline.
 */
class ProcessDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(DocumentProcessingService::class)->process($document);

        if ($document->refresh()->processing_status === DocumentStatus::Classifying) {
            ClassifyDocumentJob::dispatch($document->getKey(), $document->business_id);
        }
    }
}

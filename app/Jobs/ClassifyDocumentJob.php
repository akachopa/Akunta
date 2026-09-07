<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Services\DocumentProcessing\DocumentClassificationService;

/**
 * Tahap klasifikasi dokumen (plan.md §14.1).
 */
class ClassifyDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(DocumentClassificationService::class)->classify($document);

        if ($document->refresh()->processing_status === DocumentStatus::Extracting) {
            ExtractDocumentJob::dispatch($document->getKey(), $document->business_id);
        }
    }
}

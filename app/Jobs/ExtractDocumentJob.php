<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Services\DocumentProcessing\DocumentExtractionService;

/**
 * Tahap ekstraksi terstruktur (plan.md §14.2).
 */
class ExtractDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(DocumentExtractionService::class)->extract($document);

        if ($document->refresh()->processing_status === DocumentStatus::Normalizing) {
            NormalizeDocumentJob::dispatch($document->getKey(), $document->business_id);
        }
    }
}

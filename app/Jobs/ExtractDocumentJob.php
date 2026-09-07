<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Models\Document;
use App\Services\DocumentProcessing\DocumentExtractionService;

/**
 * Tahap ekstraksi terstruktur (plan.md §14.2).
 *
 * Tahap terakhir yang berjalan pada Phase 4. Dokumen yang lolos berhenti di READY, dan
 * NORMALIZING pada plan.md §25.1 menunggu Phase 5 yang mengubahnya menjadi transaksi.
 */
class ExtractDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(DocumentExtractionService::class)->extract($document);
    }
}

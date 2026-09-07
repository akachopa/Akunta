<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Models\Document;
use App\Services\TransactionIntelligence\TransactionIntelligenceService;

/**
 * Tahap pencocokan setelah normalisasi (plan.md §13.1 ENTITY RESOLUTION hingga
 * ACCOUNTING RULE ENGINE, §37 Phase 6–9).
 */
class MatchDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(TransactionIntelligenceService::class)->process($document);
    }
}

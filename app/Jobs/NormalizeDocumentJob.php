<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Services\TransactionNormalization\TransactionNormalizationService;

/**
 * Tahap normalisasi transaksi (plan.md §13.1 TRANSACTION GENERATOR, §37 Phase 5).
 *
 * Bila transaksi terbentuk, dokumen masuk MATCHING dan job berikutnya menjalankan
 * entity resolution, pencocokan, klasifikasi peristiwa, dan usulan jurnal.
 */
class NormalizeDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(TransactionNormalizationService::class)->normalize($document);

        if ($document->refresh()->processing_status === DocumentStatus::Matching) {
            MatchDocumentJob::dispatch($document->getKey(), $document->business_id);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Models\Document;
use App\Services\TransactionNormalization\TransactionNormalizationService;

/**
 * Tahap normalisasi transaksi (plan.md §13.1 TRANSACTION GENERATOR, §37 Phase 5).
 *
 * Tahap terakhir yang sudah dibangun. Tahap berikutnya pada plan.md §13.1 — entity
 * resolution, duplicate detector, related-document matcher — adalah Phase 6 dan Phase 7,
 * sehingga tidak ada job berikutnya yang dikirim dari sini.
 */
class NormalizeDocumentJob extends DocumentStageJob
{
    protected function run(Document $document): void
    {
        app(TransactionNormalizationService::class)->normalize($document);
    }
}

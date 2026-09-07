<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen per bisnis (plan.md §8.1 contoh "DOC-000823").
 *
 * Mengikuti pola JournalEntryNumberGenerator: nomor harus berurutan dan tidak boleh
 * bertabrakan meski beberapa berkas diunggah bersamaan. Pada PostgreSQL kondisi itu
 * dijaga advisory lock transaksional; unique index (business_id, reference) tetap menjadi
 * jaring terakhir.
 */
class DocumentReferenceGenerator
{
    private const PREFIX = 'DOC-';

    public function next(Business $business): string
    {
        $this->acquireLock($business);

        $latest = Document::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->getKey())
            ->where('reference', 'like', self::PREFIX . '%')
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, strlen(self::PREFIX))) + 1;

        return self::PREFIX . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function acquireLock(Business $business): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$business->getKey() . '|document-reference']
        );
    }
}

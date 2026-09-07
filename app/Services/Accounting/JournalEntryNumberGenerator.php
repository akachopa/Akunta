<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran journal entry per bisnis dan per bulan.
 *
 * Nomor harus berurutan dan tidak boleh bertabrakan meskipun dua request memposting
 * bersamaan. Pada PostgreSQL kondisi tersebut dijaga advisory lock transaksional; di
 * driver lain unique index (business_id, entry_number) tetap menjadi jaring terakhir.
 */
class JournalEntryNumberGenerator
{
    public function next(Business $business, Carbon $entryDate): string
    {
        $prefix = sprintf(
            '%s-%s-',
            (string) config('akunta.accounting.journal_entry_number_prefix', 'JE'),
            $entryDate->format('Ym')
        );

        $this->acquireLock($business, $prefix);

        $latest = JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->getKey())
            ->where('entry_number', 'like', $prefix.'%')
            ->orderByDesc('entry_number')
            ->value('entry_number');

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function acquireLock(Business $business, string $prefix): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$business->getKey().'|'.$prefix]
        );
    }
}

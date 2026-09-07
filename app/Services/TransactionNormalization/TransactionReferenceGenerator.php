<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Business\Models\Business;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran transaksi per bisnis (plan.md §8.2 contoh "TRX-239281").
 *
 * Mengikuti pola DocumentReferenceGenerator: satu rekening koran dapat menghasilkan ratusan
 * transaksi sekaligus, dan nomor tidak boleh bertabrakan meski dua job berjalan bersamaan.
 * Advisory lock transaksional PostgreSQL menjaga urutannya; unique index
 * (business_id, reference) tetap menjadi jaring terakhir.
 *
 * Nomor diambil sekali lalu dinaikkan di memori untuk satu batch, karena mengambilnya
 * per baris berarti satu query untuk setiap mutasi.
 */
class TransactionReferenceGenerator
{
    private const PREFIX = 'TRX-';

    /**
     * Deretan nomor berurutan sebanyak yang diminta.
     *
     * @return array<int, string>
     */
    public function nextBatch(Business $business, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $this->acquireLock($business);

        $latest = Transaction::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->getKey())
            ->where('reference', 'like', self::PREFIX . '%')
            ->orderByDesc('reference')
            ->value('reference');

        $sequence = $latest === null
            ? 0
            : (int) substr((string) $latest, strlen(self::PREFIX));

        $references = [];

        for ($index = 1; $index <= $count; $index++) {
            $references[] = self::PREFIX . str_pad((string) ($sequence + $index), 6, '0', STR_PAD_LEFT);
        }

        return $references;
    }

    private function acquireLock(Business $business): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$business->getKey() . '|transaction-reference']
        );
    }
}

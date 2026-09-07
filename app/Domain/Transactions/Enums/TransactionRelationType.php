<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Enums;

/**
 * Hubungan antar transaksi (plan.md §18).
 *
 * Duplicate dan related tidak boleh dicampur (plan.md §44.9):
 *
 * - duplicate: dua bukti untuk peristiwa yang sama, sehingga hanya satu yang boleh
 *   menghasilkan jurnal;
 * - related: dua bukti berbeda yang mendukung transaksi yang sama, misalnya faktur
 *   pembelian beserta bukti transfernya.
 */
enum TransactionRelationType: string
{
    case Duplicate = 'duplicate';
    case Related = 'related';

    public function label(): string
    {
        return match ($this) {
            self::Duplicate => 'Duplikat',
            self::Related => 'Terkait',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

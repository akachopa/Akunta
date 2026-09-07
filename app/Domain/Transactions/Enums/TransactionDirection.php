<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Enums;

/**
 * Arah aliran nilai sebuah transaksi (plan.md §8.2 `direction`).
 *
 * Arah bukan pernyataan akuntansi. INFLOW berarti nilai masuk ke bisnis menurut dokumennya,
 * bukan bahwa nilainya adalah pendapatan; OUTFLOW berarti nilai keluar, bukan bahwa ia
 * beban. plan.md §44.3 dan §44.4 melarang persamaan itu, dan penafsiran ekonominya adalah
 * pekerjaan economic event classifier pada Phase 8 beserta rule engine pada Phase 9.
 *
 * Nominal transaksi selalu positif dan arahnya yang membawa tandanya. Menyimpan nominal
 * negatif sekaligus arah keluar akan menghasilkan tanda ganda yang saling meniadakan, dan
 * setiap penjumlahan sesudahnya harus menebak konvensi mana yang berlaku.
 */
enum TransactionDirection: string
{
    case Inflow = 'inflow';
    case Outflow = 'outflow';

    public function label(): string
    {
        return match ($this) {
            self::Inflow => 'Masuk',
            self::Outflow => 'Keluar',
        };
    }

    public function isInflow(): bool
    {
        return $this === self::Inflow;
    }

    public function opposite(): self
    {
        return $this === self::Inflow ? self::Outflow : self::Inflow;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

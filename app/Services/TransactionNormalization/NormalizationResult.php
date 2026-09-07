<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

/**
 * Hasil normalisasi satu dokumen.
 *
 * Transaksi dan kesalahan tidak pernah dikembalikan bersamaan. Dokumen yang datanya tidak
 * konsisten menghasilkan nol transaksi, bukan sebagian: rekening koran yang kehilangan satu
 * baris akan membuat kas tidak pernah dapat direkonsiliasi (plan.md §19.1), dan separuh
 * laporan penjualan lebih berbahaya daripada tidak ada laporan sama sekali karena
 * kekurangannya tidak muncul sebagai kesalahan di mana pun.
 */
final class NormalizationResult
{
    /**
     * @param  array<int, NormalizedTransaction>  $transactions
     * @param  array<int, string>  $errors
     */
    private function __construct(
        public readonly array $transactions,
        public readonly array $errors,
    ) {}

    /**
     * @param  array<int, NormalizedTransaction>  $transactions
     */
    public static function of(array $transactions): self
    {
        return new self($transactions, []);
    }

    /**
     * @param  array<int, string>  $errors
     */
    public static function rejected(array $errors): self
    {
        return new self([], array_values(array_unique($errors)));
    }

    public function isAccepted(): bool
    {
        return $this->errors === [];
    }
}

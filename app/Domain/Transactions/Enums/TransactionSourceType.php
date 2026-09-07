<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Enums;

/**
 * Jenis catatan sumber yang menghasilkan sebuah transaksi (plan.md §8.2 `source_type`).
 *
 * Bukan jenis dokumen, dan bukan jenis berkas. Satu rekening koran dapat datang sebagai PDF
 * maupun CSV, tetapi keduanya menghasilkan transaksi dengan sumber yang sama sifatnya: baris
 * mutasi rekening. Yang dibedakan di sini adalah bagaimana transaksinya terbentuk, karena
 * itulah yang menentukan cara menelusurinya kembali dan cara rekonsiliasi Phase 11
 * memperlakukannya.
 */
enum TransactionSourceType: string
{
    case BankStatement = 'bank_statement';
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case Settlement = 'settlement';
    case Spreadsheet = 'spreadsheet';

    public function label(): string
    {
        return match ($this) {
            self::BankStatement => 'Mutasi Rekening',
            self::Invoice => 'Invoice',
            self::Receipt => 'Struk / Kwitansi',
            self::Settlement => 'Settlement Pembayaran',
            self::Spreadsheet => 'Baris Spreadsheet',
        };
    }

    /**
     * Sumber yang satu dokumennya menghasilkan banyak transaksi.
     *
     * Dipakai UI untuk menjelaskan mengapa sebuah dokumen menghasilkan puluhan baris, dan
     * oleh normalizer untuk memutuskan apakah `row_index` wajib ada pada source reference.
     */
    public function isRowBased(): bool
    {
        return match ($this) {
            self::BankStatement, self::Spreadsheet => true,
            default => false,
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

<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Enums;

/**
 * Jenis bukti yang melekat pada transaksi (plan.md §8.4).
 *
 * plan.md §8.4 mencontohkan dua bentuk: seluruh dokumen, dan satu baris di dalam dokumen
 * (`row_reference`). `document_field` ditambahkan untuk angka yang bukan nominal transaksi
 * tetapi menjelaskannya — biaya MDR pada settlement QRIS, misalnya. Tanpa bentuk itu, angka
 * tersebut hanya hidup di `document_fields` dan rule engine Phase 9 harus menebak field mana
 * yang relevan bagi transaksi mana.
 */
enum TransactionEvidenceType: string
{
    case Document = 'document';
    case BankRow = 'bank_row';
    case SpreadsheetRow = 'spreadsheet_row';
    case DocumentField = 'document_field';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Dokumen',
            self::BankRow => 'Baris Mutasi',
            self::SpreadsheetRow => 'Baris Spreadsheet',
            self::DocumentField => 'Data Dokumen',
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

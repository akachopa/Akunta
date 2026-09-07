<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Transactions\Enums\TransactionEvidenceType;

/**
 * Satu bukti yang akan dilekatkan pada transaksi (plan.md §8.4).
 *
 * Dibentuk normalizer, disimpan service. Pemisahan itu membuat normalizer tidak perlu tahu
 * id transaksi yang belum ada saat ia bekerja.
 */
final class EvidenceDraft
{
    public function __construct(
        public readonly TransactionEvidenceType $type,
        public readonly ?string $rowReference = null,
        public readonly ?int $pageNumber = null,
        public readonly ?DocumentFieldKey $fieldKey = null,
        public readonly ?string $note = null,
    ) {}

    public static function document(?int $pageNumber = null, ?string $note = null): self
    {
        return new self(
            type: TransactionEvidenceType::Document,
            pageNumber: $pageNumber,
            note: $note,
        );
    }

    public static function bankRow(int $rowIndex, ?int $pageNumber, ?string $note = null): self
    {
        return new self(
            type: TransactionEvidenceType::BankRow,
            rowReference: (string) $rowIndex,
            pageNumber: $pageNumber,
            note: $note,
        );
    }

    public static function spreadsheetRow(int $rowIndex, ?int $pageNumber, ?string $note = null): self
    {
        return new self(
            type: TransactionEvidenceType::SpreadsheetRow,
            rowReference: (string) $rowIndex,
            pageNumber: $pageNumber,
            note: $note,
        );
    }

    /**
     * Angka pendukung yang menjelaskan transaksi tanpa menjadi nominalnya.
     *
     * Biaya MDR pada settlement QRIS adalah contoh utamanya: ia bukan nilai transaksi kas,
     * tetapi rule engine Phase 9 memerlukannya untuk memisahkan beban dari pendapatan
     * (plan.md §19.2, §44.3).
     */
    public static function field(DocumentFieldKey $key, ?int $pageNumber, string $note): self
    {
        return new self(
            type: TransactionEvidenceType::DocumentField,
            pageNumber: $pageNumber,
            fieldKey: $key,
            note: $note,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Enums\TransactionStatus;

/**
 * Satu transaksi canonical hasil normalisasi, belum tersimpan (plan.md §8.2).
 *
 * `sourceReference` adalah kunci alami unit sumbernya dan menjadi penjaga idempotensi:
 * pasangan (document_id, source_reference) unik di database, sehingga satu baris mutasi
 * tidak pernah menghasilkan dua transaksi meski dokumennya dinormalisasi berulang kali.
 *
 * `status` boleh berbeda dari NORMALIZED. Baris yang nominal dan tanggalnya terbaca pasti
 * tetapi arahnya tidak dapat ditentukan tetap dibuat sebagai transaksi berstatus
 * NEED_REVIEW, karena membuangnya berarti kehilangan mutasi yang benar-benar terjadi
 * (plan.md §16.1 exception-based accounting).
 */
final class NormalizedTransaction
{
    /**
     * @param  array<int, EvidenceDraft>  $evidence
     */
    public function __construct(
        public readonly string $transactionDate,
        public readonly string $description,
        public readonly string $amount,
        public readonly TransactionDirection $direction,
        public readonly string $currency,
        public readonly ?string $counterpartyName,
        public readonly TransactionSourceType $sourceType,
        public readonly string $sourceReference,
        public readonly array $evidence,
        public readonly ?int $rowIndex = null,
        public readonly ?int $pageNumber = null,
        public readonly TransactionStatus $status = TransactionStatus::Normalized,
        public readonly ?string $reviewReason = null,
    ) {}
}

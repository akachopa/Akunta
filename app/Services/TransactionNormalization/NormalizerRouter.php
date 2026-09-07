<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Documents\Enums\DocumentType;

/**
 * Pemilihan normalizer berdasarkan jenis dokumen (plan.md §13.1, §37 Phase 5).
 *
 * Jenis dokumen yang belum punya normalizer dilaporkan sebagai null, bukan diarahkan ke
 * normalizer terdekat. Memaksakan bentuk faktur pada slip gaji akan menghasilkan satu
 * transaksi bernominal total gaji tanpa rincian penerimanya — angka yang tampak benar tetapi
 * salah arti — dan itu lebih berbahaya daripada dokumen yang jujur menunggu phase
 * berikutnya.
 */
class NormalizerRouter
{
    /**
     * @var array<int, DocumentNormalizer>
     */
    private array $normalizers;

    public function __construct(
        BankStatementNormalizer $bankStatement,
        TransactionListNormalizer $transactionList,
        InvoiceNormalizer $invoice,
        ReceiptNormalizer $receipt,
        SettlementNormalizer $settlement,
    ) {
        $this->normalizers = [$bankStatement, $transactionList, $invoice, $receipt, $settlement];
    }

    public function resolve(DocumentType $documentType): ?DocumentNormalizer
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($documentType)) {
                return $normalizer;
            }
        }

        return null;
    }

    /**
     * Jenis dokumen yang dapat dinormalisasi, dipakai UI dan dokumentasi phase.
     *
     * @return array<int, DocumentType>
     */
    public function supportedDocumentTypes(): array
    {
        return array_values(array_filter(
            DocumentType::cases(),
            fn (DocumentType $type): bool => $this->resolve($type) !== null
        ));
    }
}

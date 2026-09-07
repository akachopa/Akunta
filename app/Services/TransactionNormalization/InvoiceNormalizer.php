<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Models\TransactionSource;
use App\Support\Money;

/**
 * Invoice transaction generation (plan.md §37 Phase 5).
 *
 * Satu faktur menghasilkan satu transaksi, dan nominalnya adalah total dokumen. Arahnya
 * ditentukan sisi bisnis dalam dokumen itu: faktur penjualan berarti nilai mengalir masuk,
 * faktur pembelian serta tagihan utilitas dan iklan berarti mengalir keluar.
 *
 * Yang **tidak** terjadi di sini: menyimpulkan bahwa faktur berarti kas sudah bergerak.
 * Faktur adalah kewajiban atau hak tagih, dan pembayarannya biasanya datang sebagai dokumen
 * lain — baris mutasi bank. Karena itu transaksi faktur dan transaksi pembayarannya berdiri
 * sendiri sampai related-document matching Phase 7 mengaitkannya, dan penentuan apakah ia
 * piutang, penjualan tunai, atau pelunasan adalah pekerjaan economic event classifier
 * Phase 8 (plan.md §44.3, §44.4).
 *
 * Pajak dilekatkan sebagai bukti, bukan dijadikan transaksi terpisah. Rule engine Phase 9
 * memerlukan angkanya untuk memisahkan PPN dari nilai barang, dan tanpa bukti itu ia harus
 * mencari sendiri field mana yang relevan bagi transaksi mana.
 */
class InvoiceNormalizer implements DocumentNormalizer
{
    public function supports(DocumentType $documentType): bool
    {
        return in_array(
            $documentType,
            [
                DocumentType::SalesInvoice,
                DocumentType::PurchaseInvoice,
                DocumentType::UtilityBill,
                DocumentType::AdvertisingInvoice,
            ],
            true
        );
    }

    public function normalize(ExtractedDocument $source): NormalizationResult
    {
        $documentType = $source->documentType();
        $date = $source->date(DocumentFieldKey::DocumentDate);
        $total = $source->money(DocumentFieldKey::Total);

        $errors = [];

        if ($date === null) {
            $errors[] = 'Tanggal dokumen tidak terbaca, sehingga transaksinya tidak dapat ditempatkan pada periode mana pun.';
        }

        if ($total === null) {
            $errors[] = 'Total dokumen tidak terbaca, sehingga nominal transaksinya tidak diketahui.';
        } elseif (Money::isZero($total) || Money::isNegative($total)) {
            $errors[] = sprintf('Total dokumen bernilai %s, yang bukan nominal transaksi yang sah.', $total);
        }

        if ($date === null || $total === null || $errors !== []) {
            return NormalizationResult::rejected($errors);
        }

        $direction = $documentType === DocumentType::SalesInvoice
            ? TransactionDirection::Inflow
            : TransactionDirection::Outflow;

        $counterparty = $direction->isInflow()
            ? $source->firstText(DocumentFieldKey::BuyerName, DocumentFieldKey::MerchantName)
            : $source->firstText(DocumentFieldKey::IssuerName, DocumentFieldKey::MerchantName);

        return NormalizationResult::of([
            new NormalizedTransaction(
                transactionDate: $date,
                description: $this->description($source, $documentType, $counterparty),
                amount: Money::normalize($total),
                direction: $direction,
                currency: $source->currency(),
                counterpartyName: $counterparty,
                sourceType: TransactionSourceType::Invoice,
                sourceReference: TransactionSource::documentReference(),
                evidence: $this->evidence($source),
                pageNumber: $source->page(DocumentFieldKey::Total),
            ),
        ]);
    }

    /**
     * @return array<int, EvidenceDraft>
     */
    private function evidence(ExtractedDocument $source): array
    {
        $evidence = [
            EvidenceDraft::document(
                $source->page(DocumentFieldKey::DocumentNumber),
                $source->text(DocumentFieldKey::DocumentNumber)
            ),
        ];

        foreach ([DocumentFieldKey::Subtotal, DocumentFieldKey::Tax, DocumentFieldKey::DueDate] as $key) {
            $value = $key === DocumentFieldKey::DueDate
                ? $source->date($key)
                : $source->money($key);

            if ($value === null) {
                continue;
            }

            $evidence[] = EvidenceDraft::field(
                $key,
                $source->page($key),
                sprintf('%s: %s', $key->label(), $value)
            );
        }

        return $evidence;
    }

    private function description(
        ExtractedDocument $source,
        DocumentType $documentType,
        ?string $counterparty,
    ): string {
        $parts = [$documentType->label()];

        $number = $source->text(DocumentFieldKey::DocumentNumber);

        if ($number !== null && trim($number) !== '') {
            $parts[] = trim($number);
        }

        if ($counterparty !== null) {
            $parts[] = $counterparty;
        }

        return mb_strimwidth(implode(' · ', $parts), 0, 500, '…');
    }
}

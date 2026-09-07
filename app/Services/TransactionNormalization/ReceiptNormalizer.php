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
 * Normalisasi struk dan kwitansi menjadi satu transaksi (plan.md §37 Phase 5).
 *
 * Arahnya ditentukan jenis dokumennya, bukan ditebak dari isinya. Taksonomi plan.md §7.1
 * sudah memisahkan bukti penerimaan kas (`cash_receipt`, `receivable_payment_receipt`) dari
 * bukti pengeluaran, sehingga struk belanja dan kwitansi yang diterbitkan bisnis tidak
 * pernah masuk ke jenis yang sama. Bila jenisnya salah, koreksinya adalah mengoreksi jenis
 * dokumen lewat review — dan itu memicu ekstraksi serta normalisasi ulang — bukan menebak
 * arah di sini.
 */
class ReceiptNormalizer implements DocumentNormalizer
{
    /**
     * @var array<int, DocumentType>
     */
    private const INFLOW_TYPES = [
        DocumentType::CashReceipt,
        DocumentType::ReceivablePaymentReceipt,
    ];

    /**
     * @var array<int, DocumentType>
     */
    private const OUTFLOW_TYPES = [
        DocumentType::Receipt,
        DocumentType::CashDisbursement,
        DocumentType::RentReceipt,
        DocumentType::RepairReceipt,
        DocumentType::ShippingReceipt,
    ];

    public function supports(DocumentType $documentType): bool
    {
        return in_array($documentType, self::INFLOW_TYPES, true)
            || in_array($documentType, self::OUTFLOW_TYPES, true);
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

        $counterparty = $source->firstText(
            DocumentFieldKey::MerchantName,
            DocumentFieldKey::IssuerName,
            DocumentFieldKey::BuyerName
        );

        return NormalizationResult::of([
            new NormalizedTransaction(
                transactionDate: $date,
                description: $this->description($source, $documentType, $counterparty),
                amount: Money::normalize($total),
                direction: in_array($documentType, self::INFLOW_TYPES, true)
                    ? TransactionDirection::Inflow
                    : TransactionDirection::Outflow,
                currency: $source->currency(),
                counterpartyName: $counterparty,
                sourceType: TransactionSourceType::Receipt,
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
                $source->page(DocumentFieldKey::DocumentDate),
                $source->text(DocumentFieldKey::DocumentNumber)
            ),
        ];

        foreach ([DocumentFieldKey::Tax, DocumentFieldKey::PaymentMethod] as $key) {
            $value = $key === DocumentFieldKey::Tax
                ? $source->money($key)
                : $source->text($key);

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

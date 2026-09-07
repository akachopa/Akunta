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
 * Normalisasi settlement QRIS dan e-wallet (plan.md §19.2, §37 Phase 5).
 *
 * Dokumen ini memuat tiga angka yang berbeda artinya: bruto adalah nilai penjualan, MDR
 * adalah biaya yang dipotong penyelenggara, dan neto adalah uang yang benar-benar masuk ke
 * rekening. plan.md §44.3 melarang menganggap kas masuk sebagai pendapatan, jadi ketiganya
 * tidak boleh dilebur.
 *
 * Yang menjadi nominal transaksi adalah **neto**, karena transaksi canonical menggambarkan
 * perpindahan nilai yang benar-benar terjadi ke bisnis. Bruto dan MDR dilekatkan sebagai
 * bukti pada transaksi yang sama, dan rule engine Phase 9 lah yang memecahnya menjadi
 * pendapatan sebesar bruto dan beban sebesar MDR. Memecahnya di sini akan menghasilkan dua
 * transaksi yang salah satunya bukan perpindahan nilai apa pun, dan rekonsiliasi bank
 * Phase 11 akan mencari padanan kas untuk transaksi yang tidak pernah menyentuh rekening.
 */
class SettlementNormalizer implements DocumentNormalizer
{
    public function supports(DocumentType $documentType): bool
    {
        return in_array(
            $documentType,
            [DocumentType::QrisSettlement, DocumentType::EwalletSettlement],
            true
        );
    }

    public function normalize(ExtractedDocument $source): NormalizationResult
    {
        $documentType = $source->documentType();
        $date = $source->firstDate(DocumentFieldKey::SettlementDate, DocumentFieldKey::DocumentDate);
        $gross = $source->money(DocumentFieldKey::GrossAmount);
        $mdr = $source->money(DocumentFieldKey::MdrFee);
        $net = $source->money(DocumentFieldKey::NetAmount);

        $errors = [];

        if ($date === null) {
            $errors[] = 'Tanggal settlement tidak terbaca, sehingga transaksinya tidak dapat ditempatkan pada periode mana pun.';
        }

        if ($net === null) {
            $errors[] = 'Nilai neto tidak terbaca, sehingga jumlah uang yang diterima tidak diketahui.';
        } elseif (Money::isZero($net) || Money::isNegative($net)) {
            $errors[] = sprintf('Nilai neto settlement adalah %s, yang bukan nominal transaksi yang sah.', $net);
        }

        if ($gross !== null && $mdr !== null && $net !== null && ! Money::equals(Money::subtract($gross, $mdr), $net)) {
            $errors[] = sprintf(
                'Settlement tidak konsisten: bruto %s − MDR %s tidak sama dengan neto %s.',
                $gross,
                $mdr,
                $net
            );
        }

        if ($date === null || $net === null || $errors !== []) {
            return NormalizationResult::rejected($errors);
        }

        $counterparty = $source->firstText(DocumentFieldKey::MerchantName, DocumentFieldKey::IssuerName);

        return NormalizationResult::of([
            new NormalizedTransaction(
                transactionDate: $date,
                description: $this->description($documentType, $counterparty, $source),
                amount: Money::normalize($net),
                direction: TransactionDirection::Inflow,
                currency: $source->currency(),
                counterpartyName: $counterparty,
                sourceType: TransactionSourceType::Settlement,
                sourceReference: TransactionSource::documentReference(),
                evidence: $this->evidence($source, $gross, $mdr),
                pageNumber: $source->page(DocumentFieldKey::NetAmount),
            ),
        ]);
    }

    /**
     * @return array<int, EvidenceDraft>
     */
    private function evidence(ExtractedDocument $source, ?string $gross, ?string $mdr): array
    {
        $evidence = [
            EvidenceDraft::document(
                $source->page(DocumentFieldKey::SettlementDate),
                $source->text(DocumentFieldKey::MerchantId)
            ),
        ];

        if ($gross !== null) {
            $evidence[] = EvidenceDraft::field(
                DocumentFieldKey::GrossAmount,
                $source->page(DocumentFieldKey::GrossAmount),
                sprintf('Nilai penjualan sebelum potongan: %s', $gross)
            );
        }

        if ($mdr !== null) {
            $evidence[] = EvidenceDraft::field(
                DocumentFieldKey::MdrFee,
                $source->page(DocumentFieldKey::MdrFee),
                sprintf('Biaya yang dipotong penyelenggara: %s', $mdr)
            );
        }

        $count = $source->integer(DocumentFieldKey::TransactionCount);

        if ($count !== null) {
            $evidence[] = EvidenceDraft::field(
                DocumentFieldKey::TransactionCount,
                $source->page(DocumentFieldKey::TransactionCount),
                sprintf('Mewakili %d transaksi penjualan', $count)
            );
        }

        return $evidence;
    }

    private function description(
        DocumentType $documentType,
        ?string $counterparty,
        ExtractedDocument $source,
    ): string {
        $parts = [$documentType->label()];

        if ($counterparty !== null) {
            $parts[] = $counterparty;
        }

        $merchantId = $source->text(DocumentFieldKey::MerchantId);

        if ($merchantId !== null && trim($merchantId) !== '') {
            $parts[] = trim($merchantId);
        }

        return mb_strimwidth(implode(' · ', $parts), 0, 500, '…');
    }
}

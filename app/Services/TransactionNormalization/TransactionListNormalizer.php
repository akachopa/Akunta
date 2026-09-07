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
 * Spreadsheet transaction parser (plan.md §37 Phase 5).
 *
 * Laporan POS, laporan marketplace, dan laporan konsinyasi berbentuk daftar transaksi
 * dengan satu kolom nilai bertanda. Tandanya itulah yang menentukan arah: penjualan positif
 * menjadi INFLOW, refund atau potongan negatif menjadi OUTFLOW. Nominal yang disimpan selalu
 * positif, karena arah sudah dibawa kolomnya sendiri.
 *
 * Perbedaannya dari rekening koran bukan sekadar bentuk kolom. Baris di sini adalah peristiwa
 * usaha — satu shift kasir, satu pesanan, satu potongan platform — sedangkan baris rekening
 * koran adalah perpindahan kas. Keduanya dapat merujuk uang yang sama, dan mengaitkannya
 * adalah pekerjaan related-document matching Phase 7. Sampai itu ada, keduanya berdiri
 * sendiri, dan itu sengaja: plan.md §44.9 melarang menggabungkan duplicate dengan
 * related-document, dan menggabungkannya di tahap ini akan menghapus salah satu sisi
 * tanpa jejak.
 */
class TransactionListNormalizer implements DocumentNormalizer
{
    public function supports(DocumentType $documentType): bool
    {
        return in_array(
            $documentType,
            [DocumentType::PosReport, DocumentType::MarketplaceReport, DocumentType::ConsignmentReport],
            true
        );
    }

    public function normalize(ExtractedDocument $source): NormalizationResult
    {
        if ($source->rows === []) {
            return NormalizationResult::rejected([
                'Dokumen tidak memuat satu pun baris transaksi yang dapat dinormalisasi.',
            ]);
        }

        $documentType = $source->documentType();
        $currency = $source->currency();
        $counterparty = $this->counterparty($documentType, $source);

        $transactions = [];
        $errors = [];
        $signedTotal = Money::zero();

        foreach ($source->rows as $index => $row) {
            $date = $source->rowDate($row, DocumentFieldKey::RowDate)
                ?? $source->firstDate(DocumentFieldKey::DocumentDate, DocumentFieldKey::PeriodEnd);

            if ($date === null) {
                $errors[] = sprintf('Baris ke-%d tidak memiliki tanggal.', $index + 1);

                continue;
            }

            $amount = $source->rowMoney($row, DocumentFieldKey::RowAmount);

            if ($amount === null) {
                $errors[] = sprintf('Baris ke-%d tidak memiliki nominal.', $index + 1);

                continue;
            }

            if (Money::isZero($amount)) {
                $errors[] = sprintf('Baris ke-%d bernilai nol, sehingga bukan transaksi.', $index + 1);

                continue;
            }

            $signedTotal = Money::add($signedTotal, $amount);

            $page = $source->rowPage($row);
            $description = $source->rowText($row, DocumentFieldKey::RowDescription);

            $transactions[] = new NormalizedTransaction(
                transactionDate: $date,
                description: $this->description($description, $documentType),
                amount: Money::abs($amount),
                direction: Money::isNegative($amount)
                    ? TransactionDirection::Outflow
                    : TransactionDirection::Inflow,
                currency: $currency,
                counterpartyName: $counterparty,
                sourceType: TransactionSourceType::Spreadsheet,
                sourceReference: TransactionSource::rowReference($index),
                evidence: [
                    EvidenceDraft::spreadsheetRow($index, $page, $description),
                    EvidenceDraft::document($page, $source->document->original_filename),
                ],
                rowIndex: $index,
                pageNumber: $page,
            );
        }

        $errors = array_merge($errors, $this->totalIdentity($source, $signedTotal));

        if ($errors !== []) {
            return NormalizationResult::rejected($errors);
        }

        return NormalizationResult::of($transactions);
    }

    /**
     * Jumlah baris bertanda = total laporan, bila totalnya tertulis.
     *
     * @return array<int, string>
     */
    private function totalIdentity(ExtractedDocument $source, string $signedTotal): array
    {
        $total = $source->money(DocumentFieldKey::Total);

        if ($total === null || Money::equals($total, $signedTotal)) {
            return [];
        }

        return [sprintf(
            'Jumlah baris %s tidak sama dengan total laporan %s, sehingga ada baris yang belum terbaca.',
            $signedTotal,
            $total
        )];
    }

    /**
     * Pihak lawan pada laporan platform adalah platformnya sendiri.
     *
     * Laporan POS tidak punya pihak lawan tunggal: pembelinya adalah pelanggan berbeda pada
     * setiap baris, dan menyebut nama outlet sendiri sebagai pihak lawan akan membuat bisnis
     * bertransaksi dengan dirinya sendiri di mata entity resolution Phase 6.
     */
    private function counterparty(DocumentType $documentType, ExtractedDocument $source): ?string
    {
        if ($documentType === DocumentType::PosReport) {
            return null;
        }

        return $source->firstText(DocumentFieldKey::MerchantName, DocumentFieldKey::IssuerName);
    }

    private function description(?string $rowDescription, DocumentType $documentType): string
    {
        $description = $rowDescription !== null && trim($rowDescription) !== ''
            ? trim($rowDescription)
            : 'Transaksi tanpa keterangan';

        return mb_strimwidth(sprintf('%s · %s', $description, $documentType->label()), 0, 500, '…');
    }
}

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
 * Bank row parser (plan.md §37 Phase 5).
 *
 * Satu rekening koran menghasilkan satu transaksi per baris mutasi — inilah acceptance
 * pertama Phase 5. Arahnya diturunkan dari kolomnya: debit berarti uang keluar dari
 * rekening, kredit berarti uang masuk. Konvensi itu adalah konvensi pemilik rekening, yang
 * dipakai seluruh rekening koran bank Indonesia yang menjadi target plan.md §2.1.
 *
 * Baris yang memuat debit dan kredit sekaligus, atau tidak memuat keduanya, ditolak alih-alih
 * ditebak. Kolom yang tergeser saat parsing menghasilkan tepat bentuk itu, dan menebaknya
 * berarti memasukkan uang ke sisi yang salah pada seluruh baris berikutnya.
 *
 * Identitas saldo diperiksa ulang di sini, bukan hanya saat ekstraksi. Reviewer dapat
 * mengoreksi saldo awal atau akhir setelah ekstraksi selesai, dan koreksi itu dapat membuat
 * mutasi yang semula konsisten tidak lagi konsisten. Rekening koran yang tidak balance tidak
 * boleh menghasilkan kas yang tidak akan pernah dapat direkonsiliasi (plan.md §19.1).
 */
class BankStatementNormalizer implements DocumentNormalizer
{
    public function supports(DocumentType $documentType): bool
    {
        return $documentType === DocumentType::BankStatement;
    }

    public function normalize(ExtractedDocument $source): NormalizationResult
    {
        if ($source->rows === []) {
            return NormalizationResult::rejected([
                'Rekening koran tidak memuat satu pun baris mutasi yang dapat dijadikan transaksi.',
            ]);
        }

        $currency = $source->currency();
        $accountLabel = $this->accountLabel($source);

        $transactions = [];
        $errors = [];
        $debitTotal = Money::zero();
        $creditTotal = Money::zero();

        foreach ($source->rows as $index => $row) {
            $date = $source->rowDate($row, DocumentFieldKey::RowDate);

            if ($date === null) {
                $errors[] = sprintf('Baris mutasi ke-%d tidak memiliki tanggal.', $index + 1);

                continue;
            }

            $debit = $source->rowMoney($row, DocumentFieldKey::RowDebit);
            $credit = $source->rowMoney($row, DocumentFieldKey::RowCredit);

            $hasDebit = $debit !== null && ! Money::isZero($debit);
            $hasCredit = $credit !== null && ! Money::isZero($credit);

            if ($hasDebit && $hasCredit) {
                $errors[] = sprintf(
                    'Baris mutasi ke-%d memuat debit dan kredit sekaligus, sehingga arah uangnya tidak dapat dipastikan.',
                    $index + 1
                );

                continue;
            }

            if (! $hasDebit && ! $hasCredit) {
                $errors[] = sprintf('Baris mutasi ke-%d tidak memuat nominal debit maupun kredit.', $index + 1);

                continue;
            }

            $amount = $hasDebit ? (string) $debit : (string) $credit;

            if (Money::isNegative($amount)) {
                $errors[] = sprintf('Baris mutasi ke-%d memuat nominal negatif.', $index + 1);

                continue;
            }

            $debitTotal = $hasDebit ? Money::add($debitTotal, $amount) : $debitTotal;
            $creditTotal = $hasCredit ? Money::add($creditTotal, $amount) : $creditTotal;

            $page = $source->rowPage($row);
            $description = $source->rowText($row, DocumentFieldKey::RowDescription);

            $transactions[] = new NormalizedTransaction(
                transactionDate: $date,
                description: $this->description($description, $accountLabel),
                amount: Money::normalize($amount),
                direction: $hasDebit ? TransactionDirection::Outflow : TransactionDirection::Inflow,
                currency: $currency,
                /*
                 * Nama pihak lawan tidak ditebak dari keterangan mutasi. "TRSF PT SUMBER
                 * MAKMUR" memang memuat namanya, tetapi memisahkan nama dari kode bank dan
                 * nomor referensi adalah pekerjaan entity resolution Phase 6 yang memiliki
                 * alias dan riwayat sebagai bahan; regex di sini hanya akan menghasilkan
                 * entity palsu yang sulit dibersihkan.
                 */
                counterpartyName: null,
                sourceType: TransactionSourceType::BankStatement,
                sourceReference: TransactionSource::rowReference($index),
                evidence: [
                    EvidenceDraft::bankRow($index, $page, $description),
                    EvidenceDraft::document($source->page(DocumentFieldKey::AccountNumber), $accountLabel),
                ],
                rowIndex: $index,
                pageNumber: $page,
            );
        }

        $errors = array_merge($errors, $this->balanceIdentity($source, $debitTotal, $creditTotal));

        if ($errors !== []) {
            return NormalizationResult::rejected($errors);
        }

        return NormalizationResult::of($transactions);
    }

    /**
     * saldo awal + kredit − debit = saldo akhir (plan.md §19.1).
     *
     * @return array<int, string>
     */
    private function balanceIdentity(ExtractedDocument $source, string $debit, string $credit): array
    {
        $opening = $source->money(DocumentFieldKey::OpeningBalance);
        $closing = $source->money(DocumentFieldKey::ClosingBalance);

        if ($opening === null || $closing === null) {
            return [];
        }

        $expected = Money::subtract(Money::add($opening, $credit), $debit);

        if (Money::equals($expected, $closing)) {
            return [];
        }

        return [sprintf(
            'Mutasi tidak menjelaskan perubahan saldo: saldo awal %s + kredit %s − debit %s menghasilkan %s, sedangkan saldo akhir tertulis %s.',
            $opening,
            $credit,
            $debit,
            $expected,
            $closing
        )];
    }

    private function accountLabel(ExtractedDocument $source): string
    {
        $bank = $source->text(DocumentFieldKey::BankName);
        $account = $source->text(DocumentFieldKey::AccountNumber);

        $parts = array_values(array_filter([$bank, $account], static fn (?string $part): bool => $part !== null && trim($part) !== ''));

        return $parts === [] ? 'Rekening bank' : implode(' ', $parts);
    }

    private function description(?string $rowDescription, string $accountLabel): string
    {
        $description = $rowDescription !== null && trim($rowDescription) !== ''
            ? trim($rowDescription)
            : 'Mutasi tanpa keterangan';

        return mb_strimwidth(sprintf('%s · %s', $description, $accountLabel), 0, 500, '…');
    }
}

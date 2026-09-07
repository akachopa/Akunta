<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentFieldKind;
use App\Domain\Documents\Enums\DocumentType;
use App\Services\Ai\DocumentExtractionResult;
use App\Services\Ai\ExtractedFieldValue;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Lapisan aturan antara output model dan pembukuan (plan.md §44.2, §37 Phase 4).
 *
 * Worker sudah memvalidasi hasilnya sendiri, dan validasi itu tetap dipakai. Yang
 * dikerjakan di sini adalah pemeriksaan ulang yang independen, dengan alasan yang bukan
 * sekadar kehati-hatian:
 *
 * 1. **Bentuk nilai.** Yang tersimpan di database adalah string yang dikirim worker, bukan
 *    objek Decimal di dalam prosesnya. String itulah yang harus lolos, dan hanya Laravel
 *    yang dapat memeriksanya terhadap kolom tujuannya (plan.md §44.14).
 * 2. **Kelengkapan canonical.** Phase 5 membaca proyeksi §8.1: tanggal dan nilai dokumen.
 *    Ekstraksi tanpa keduanya secara teknis "valid" bagi worker tetapi tidak dapat
 *    menghasilkan transaksi apa pun.
 * 3. **Aritmetika.** Identitas dokumen dihitung ulang dengan bcmath atas nilai yang akan
 *    benar-benar disimpan. Bila worker keliru, atau versinya berbeda dengan yang
 *    diasumsikan Laravel, selisihnya muncul di sini alih-alih menjadi jurnal yang tidak
 *    dapat direkonsiliasi.
 *
 * Penolakan bukan kegagalan sistem: dokumennya diteruskan ke reviewer beserta alasannya.
 */
class ExtractionValidator
{
    public function validate(DocumentType $documentType, DocumentExtractionResult $result): ValidatedExtraction
    {
        $errors = $this->workerVerdict($result);

        $fields = [];

        foreach ($result->fields as $value) {
            if (! $value->hasValue()) {
                continue;
            }

            $field = $this->prepare($value);

            if ($field === null) {
                $errors[] = sprintf(
                    'Nilai %s tidak dapat dibaca sebagai %s: [%s].',
                    $value->key->label(),
                    $value->key->kind()->label(),
                    mb_strimwidth((string) $value->value, 0, 40, '…')
                );

                continue;
            }

            $fields[] = $field;
        }

        $rows = [];

        foreach ($result->rows as $row) {
            $prepared = [];

            foreach ($row as $value) {
                if (! $value->hasValue()) {
                    continue;
                }

                $field = $this->prepare($value);

                if ($field === null) {
                    $errors[] = sprintf(
                        'Baris mutasi ke-%d memuat %s yang tidak dapat dibaca: [%s].',
                        ($value->rowIndex ?? 0) + 1,
                        $value->key->label(),
                        mb_strimwidth((string) $value->value, 0, 40, '…')
                    );

                    continue;
                }

                $prepared[$field->key->value] = $field;
            }

            /*
             * Baris tanpa satu pun nilai dibuang, bukan disimpan sebagai baris kosong.
             * Baris kosong akan terhitung sebagai mutasi bernilai nol oleh pemeriksaan
             * saldo dan oleh Phase 5.
             */
            if ($prepared !== []) {
                $rows[] = $prepared;
                $fields = array_merge($fields, array_values($prepared));
            }
        }

        $documentFields = [];

        foreach ($fields as $field) {
            if ($field->rowIndex === null) {
                $documentFields[$field->key->value] = $field;
            }
        }

        $errors = array_merge(
            $errors,
            $this->missingRequired($documentType, $documentFields),
            $this->arithmetic($documentType, $documentFields, $rows),
        );

        $errors = array_values(array_unique($errors));

        return new ValidatedExtraction(
            fields: $errors === [] ? $fields : [],
            errors: $errors,
        );
    }

    /**
     * Memvalidasi satu nilai yang diberikan reviewer (plan.md §17.1).
     *
     * Koreksi manusia melewati validator yang sama dengan output model. Manusia dapat salah
     * mengetik, dan tanggal 31 Februari yang berasal dari reviewer sama merusaknya dengan
     * yang berasal dari model. Yang dibedakan hanyalah confidence-nya, bukan pemeriksaannya.
     */
    public function prepareCorrection(DocumentFieldKey $key, string $raw): ?PreparedField
    {
        return $this->prepare(new ExtractedFieldValue(
            key: $key,
            value: $raw,
            confidence: '1.0000',
            pageNumber: null,
            sourceText: null,
        ));
    }

    /**
     * Verdict worker dipakai sebagai alasan, bukan diabaikan maupun dipercaya sendirian.
     *
     * @return array<int, string>
     */
    private function workerVerdict(DocumentExtractionResult $result): array
    {
        if ($result->valid) {
            return [];
        }

        return $result->validationErrors !== []
            ? $result->validationErrors
            : ['AI worker menandai hasil ekstraksi tidak valid tanpa menyebutkan alasannya.'];
    }

    /**
     * Mengubah satu nilai mentah menjadi baris siap simpan, atau null bila bentuknya salah.
     */
    private function prepare(ExtractedFieldValue $value): ?PreparedField
    {
        $kind = $value->key->kind();
        $raw = trim((string) $value->value);

        $text = null;
        $number = null;
        $date = null;

        switch ($kind) {
            case DocumentFieldKind::Money:
                $number = $this->money($raw);

                break;

            case DocumentFieldKind::Integer:
                $number = $this->integer($raw);

                break;

            case DocumentFieldKind::Date:
                $date = $this->date($raw);

                break;

            case DocumentFieldKind::Text:
                // plan.md §30: nilai teks dari dokumen tidak boleh dipakai sebagai kolom
                // tanpa batas panjang, karena isinya berasal dari luar sistem.
                $text = mb_strimwidth($raw, 0, 500, '…');

                break;
        }

        if ($text === null && $number === null && $date === null) {
            return null;
        }

        return new PreparedField(
            key: $value->key,
            kind: $kind,
            valueText: $text,
            valueNumber: $number,
            valueDate: $date,
            confidence: $value->confidence,
            pageNumber: $value->pageNumber,
            sourceText: $value->sourceText === null
                ? null
                : mb_strimwidth($value->sourceText, 0, 500, '…'),
            rowIndex: $value->rowIndex,
        );
    }

    private function money(string $raw): ?string
    {
        try {
            return Money::normalize($raw);
        } catch (InvalidArgumentException) {
            /*
             * Nilai uang yang masih memuat pemisah ribuan, simbol mata uang, atau kata
             * ditolak di sini alih-alih "dibersihkan". Membersihkannya berarti menebak apa
             * yang dimaksud model, dan tebakan atas angka uang adalah hal yang paling tidak
             * boleh dilakukan sistem akuntansi (plan.md §44.2).
             */
            return null;
        }
    }

    private function integer(string $raw): ?string
    {
        return preg_match('/^\d+$/', $raw) === 1 ? $raw : null;
    }

    private function date(string $raw): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $matches) !== 1) {
            return null;
        }

        /*
         * checkdate() dipakai, bukan parser tanggal, karena parser membetulkan tanggal yang
         * tidak ada: 2026-02-30 menjadi 2026-03-02. Tanggal yang "dibetulkan" akan masuk ke
         * periode akuntansi yang salah tanpa satu pun tanda bahwa nilainya diubah.
         */
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $raw : null;
    }

    /**
     * Field canonical yang wajib ada agar dokumennya berguna bagi phase berikutnya.
     *
     * Daftar ini lebih pendek daripada daftar field wajib extractor, dan memang harus
     * begitu: yang diperiksa di sini bukan kelengkapan dokumen, melainkan kelengkapan
     * proyeksi plan.md §8.1 yang dibaca transaction generator.
     *
     * @return array<int, DocumentFieldKey>
     */
    private function requiredKeys(DocumentType $documentType): array
    {
        return match ($documentType) {
            DocumentType::BankStatement => [
                DocumentFieldKey::OpeningBalance,
                DocumentFieldKey::ClosingBalance,
            ],

            DocumentType::QrisSettlement, DocumentType::EwalletSettlement => [
                DocumentFieldKey::SettlementDate,
                DocumentFieldKey::GrossAmount,
                DocumentFieldKey::MdrFee,
                DocumentFieldKey::NetAmount,
            ],

            default => [
                DocumentFieldKey::DocumentDate,
                DocumentFieldKey::Total,
            ],
        };
    }

    /**
     * @param  array<string, PreparedField>  $fields
     * @return array<int, string>
     */
    private function missingRequired(DocumentType $documentType, array $fields): array
    {
        $errors = [];

        foreach ($this->requiredKeys($documentType) as $key) {
            if (! isset($fields[$key->value])) {
                $errors[] = sprintf('%s tidak terbaca pada dokumen.', $key->label());
            }
        }

        return $errors;
    }

    /**
     * Identitas aritmetika per jenis dokumen, dihitung ulang dengan bcmath.
     *
     * @param  array<string, PreparedField>  $fields
     * @param  array<int, array<string, PreparedField>>  $rows
     * @return array<int, string>
     */
    private function arithmetic(DocumentType $documentType, array $fields, array $rows): array
    {
        return match ($documentType) {
            DocumentType::BankStatement => $this->bankStatementArithmetic($fields, $rows),
            DocumentType::QrisSettlement, DocumentType::EwalletSettlement => $this->settlementArithmetic($fields),
            default => $this->invoiceArithmetic($fields),
        };
    }

    /**
     * subtotal + pajak = total (plan.md §14.2).
     *
     * @param  array<string, PreparedField>  $fields
     * @return array<int, string>
     */
    private function invoiceArithmetic(array $fields): array
    {
        $errors = [];
        $total = $this->amount($fields, DocumentFieldKey::Total);

        if ($total !== null && Money::isNegative($total)) {
            $errors[] = 'Total dokumen bernilai negatif.';
        }

        $subtotal = $this->amount($fields, DocumentFieldKey::Subtotal);
        $tax = $this->amount($fields, DocumentFieldKey::Tax);

        if ($total === null || $subtotal === null || $tax === null) {
            return $errors;
        }

        $expected = Money::add($subtotal, $tax);

        if (! Money::equals($expected, $total)) {
            $errors[] = sprintf(
                'Total tidak konsisten: subtotal %s + pajak %s menghasilkan %s, sedangkan total tertulis %s.',
                $subtotal,
                $tax,
                $expected,
                $total
            );
        }

        return $errors;
    }

    /**
     * bruto − MDR = neto (plan.md §19.2, §44.3).
     *
     * @param  array<string, PreparedField>  $fields
     * @return array<int, string>
     */
    private function settlementArithmetic(array $fields): array
    {
        $gross = $this->amount($fields, DocumentFieldKey::GrossAmount);
        $mdr = $this->amount($fields, DocumentFieldKey::MdrFee);
        $net = $this->amount($fields, DocumentFieldKey::NetAmount);

        $errors = [];

        if ($mdr !== null && Money::isNegative($mdr)) {
            $errors[] = 'Biaya MDR bernilai negatif.';
        }

        if ($gross === null || $mdr === null || $net === null) {
            return $errors;
        }

        $expected = Money::subtract($gross, $mdr);

        if (! Money::equals($expected, $net)) {
            $errors[] = sprintf(
                'Settlement tidak konsisten: bruto %s − MDR %s menghasilkan %s, sedangkan neto tertulis %s.',
                $gross,
                $mdr,
                $expected,
                $net
            );
        }

        return $errors;
    }

    /**
     * saldo awal + kredit − debit = saldo akhir (plan.md §19.1).
     *
     * Pemeriksaan paling bernilai pada Phase 4: bila identitas ini tidak terpenuhi, ada
     * baris yang terlewat atau angka yang salah baca, dan kas yang dihasilkannya tidak akan
     * pernah dapat direkonsiliasi.
     *
     * @param  array<string, PreparedField>  $fields
     * @param  array<int, array<string, PreparedField>>  $rows
     * @return array<int, string>
     */
    private function bankStatementArithmetic(array $fields, array $rows): array
    {
        if ($rows === []) {
            return ['Rekening koran tidak menghasilkan satu pun baris mutasi.'];
        }

        $errors = [];
        $undated = 0;

        foreach ($rows as $row) {
            if (! isset($row[DocumentFieldKey::RowDate->value])) {
                $undated++;
            }
        }

        if ($undated > 0) {
            $errors[] = sprintf('Ada %d baris mutasi tanpa tanggal yang dapat dibaca.', $undated);
        }

        $opening = $this->amount($fields, DocumentFieldKey::OpeningBalance);
        $closing = $this->amount($fields, DocumentFieldKey::ClosingBalance);

        if ($opening === null || $closing === null) {
            return $errors;
        }

        $debit = $this->sumRows($rows, DocumentFieldKey::RowDebit);
        $credit = $this->sumRows($rows, DocumentFieldKey::RowCredit);

        $expected = Money::subtract(Money::add($opening, $credit), $debit);

        if (! Money::equals($expected, $closing)) {
            $errors[] = sprintf(
                'Saldo tidak konsisten: saldo awal %s + kredit %s − debit %s menghasilkan %s, sedangkan saldo akhir tertulis %s.',
                $opening,
                $credit,
                $debit,
                $expected,
                $closing
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, PreparedField>  $fields
     */
    private function amount(array $fields, DocumentFieldKey $key): ?string
    {
        return $fields[$key->value]->valueNumber ?? null;
    }

    /**
     * @param  array<int, array<string, PreparedField>>  $rows
     */
    private function sumRows(array $rows, DocumentFieldKey $key): string
    {
        $total = Money::zero();

        foreach ($rows as $row) {
            $value = $row[$key->value]->valueNumber ?? null;

            if ($value !== null) {
                $total = Money::add($total, $value);
            }
        }

        return $total;
    }
}

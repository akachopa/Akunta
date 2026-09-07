<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Models\DocumentField;
use LogicException;

/**
 * Pembacaan nilai dokumen untuk normalizer.
 *
 * Normalizer bekerja dari `document_fields`, bukan dari halaman hasil parse maupun dari raw
 * output provider. Alasannya adalah batas Phase 4: hanya field milik ekstraksi yang
 * berstatus `accepted` yang pernah lolos validasi, dan hanya nilai itu yang boleh menjadi
 * transaksi (plan.md §37 Phase 4 "invalid output tidak masuk transaction pipeline").
 *
 * Kelas ini juga menyediakan pembacaan bertipe. Tanpa itu, setiap normalizer harus
 * mengetahui bahwa uang tersimpan pada `value_number` dan tanggal pada `value_date`, dan
 * kekeliruan satu kolom akan menghasilkan nominal nol yang tampak sah.
 */
final class ExtractedDocument
{
    /**
     * @param  array<string, DocumentField>  $fields
     * @param  array<int, array<string, DocumentField>>  $rows
     */
    private function __construct(
        public readonly Document $document,
        public readonly DocumentExtraction $extraction,
        public readonly array $fields,
        public readonly array $rows,
    ) {}

    public static function for(Document $document, DocumentExtraction $extraction): self
    {
        $fields = [];
        $rows = [];

        foreach ($extraction->fields as $field) {
            if ($field->row_index === null) {
                $fields[$field->field_key->value] = $field;

                continue;
            }

            $rows[$field->row_index][$field->field_key->value] = $field;
        }

        ksort($rows);

        return new self($document, $extraction, $fields, $rows);
    }

    /**
     * Jenis dokumen yang berlaku, dipastikan ada.
     *
     * Normalizer hanya dijalankan setelah router mencocokkan jenis dokumennya, sehingga
     * nilainya tidak mungkin kosong di sini. Ketegasan itu dinyatakan satu kali agar tiap
     * normalizer tidak perlu memeriksa keadaan yang tidak dapat terjadi.
     */
    public function documentType(): DocumentType
    {
        $documentType = $this->document->document_type;

        if ($documentType === null) {
            throw new LogicException('Normalisasi dijalankan atas dokumen yang jenisnya belum ditetapkan.');
        }

        return $documentType;
    }

    public function money(DocumentFieldKey $key): ?string
    {
        return $this->fields[$key->value]->value_number ?? null;
    }

    public function integer(DocumentFieldKey $key): ?int
    {
        $value = $this->fields[$key->value]->value_number ?? null;

        return $value === null ? null : (int) $value;
    }

    public function date(DocumentFieldKey $key): ?string
    {
        return ($this->fields[$key->value] ?? null)?->value_date?->toDateString();
    }

    public function text(DocumentFieldKey $key): ?string
    {
        return $this->fields[$key->value]->value_text ?? null;
    }

    public function page(DocumentFieldKey $key): ?int
    {
        return $this->fields[$key->value]->page_number ?? null;
    }

    public function has(DocumentFieldKey $key): bool
    {
        return isset($this->fields[$key->value]);
    }

    /**
     * Tanggal pertama yang tersedia di antara beberapa kunci.
     *
     * Dokumen menyebut tanggalnya dengan nama yang berbeda-beda — tanggal settlement,
     * akhir periode, tanggal dokumen — dan transaksi hanya memerlukan satu di antaranya.
     */
    public function firstDate(DocumentFieldKey ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->date($key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Nama pihak lawan pertama yang tersedia (plan.md §9 entity resolution memakainya nanti).
     */
    public function firstText(DocumentFieldKey ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->text($key);

            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Mata uang dokumen, dengan default bisnis sebagai cadangan.
     *
     * Dokumen UMKM sering tidak menuliskan mata uangnya sama sekali. Menolaknya karena itu
     * akan menahan hampir semua struk, sedangkan menebak mata uang asing tanpa dasar jauh
     * lebih berbahaya: yang dipakai adalah default aplikasi, bukan tebakan atas isi dokumen.
     */
    public function currency(): string
    {
        $currency = $this->document->currency ?? $this->text(DocumentFieldKey::Currency);

        if (is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency) === 1) {
            return mb_strtoupper($currency);
        }

        return mb_strtoupper((string) config('akunta.money.default_currency', 'IDR'));
    }

    /**
     * @param  array<string, DocumentField>  $row
     */
    public function rowMoney(array $row, DocumentFieldKey $key): ?string
    {
        return $row[$key->value]->value_number ?? null;
    }

    /**
     * @param  array<string, DocumentField>  $row
     */
    public function rowDate(array $row, DocumentFieldKey $key): ?string
    {
        return ($row[$key->value] ?? null)?->value_date?->toDateString();
    }

    /**
     * @param  array<string, DocumentField>  $row
     */
    public function rowText(array $row, DocumentFieldKey $key): ?string
    {
        $value = $row[$key->value]->value_text ?? null;

        return $value === null ? null : trim($value);
    }

    /**
     * @param  array<string, DocumentField>  $row
     */
    public function rowPage(array $row): ?int
    {
        foreach ($row as $field) {
            if ($field->page_number !== null) {
                return $field->page_number;
            }
        }

        return null;
    }
}

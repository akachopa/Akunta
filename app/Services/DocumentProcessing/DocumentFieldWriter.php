<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;

/**
 * Menulis field hasil ekstraksi dan proyeksi canonical-nya (plan.md §23.3, §8.1).
 *
 * Dua pekerjaan yang tampak berbeda tetapi harus selalu terjadi bersamaan: baris
 * `document_fields` sebagai sumber kebenaran beserta buktinya, dan kolom pada `documents`
 * sebagai proyeksi yang dibaca inbox serta transaction generator. Bila keduanya terpisah,
 * akan muncul dokumen yang totalnya berbeda dari total field-nya, dan tidak ada satu pun
 * tempat yang dapat menyatakan mana yang benar.
 */
class DocumentFieldWriter
{
    /**
     * @param  array<int, PreparedField>  $fields
     */
    public function write(Document $document, DocumentExtraction $extraction, array $fields): void
    {
        /*
         * Field adalah data turunan dari ekstraksi yang sedang berlaku, jadi hasil
         * percobaan sebelumnya dibuang. Membiarkannya akan membuat satu dokumen memiliki
         * dua nilai untuk field yang sama tanpa cara memilih di antaranya. Buktinya tidak
         * hilang: setiap percobaan tetap tersimpan pada `document_extractions.raw_output`
         * (plan.md §31).
         */
        $document->fields()->delete();

        foreach ($fields as $field) {
            $extraction->fields()->create($field->toAttributes() + [
                'document_id' => $document->getKey(),
                'business_id' => $document->business_id,
            ]);
        }
    }

    /**
     * Kolom canonical `documents` yang diturunkan dari field tingkat dokumen.
     *
     * Kolom yang tidak terisi dikembalikan sebagai null, bukan dihilangkan dari array.
     * Dokumen yang diproses ulang harus kehilangan nilai lamanya bila ekstraksi baru tidak
     * lagi menemukannya; menyisakan nilai lama akan menampilkan total yang tidak berasal
     * dari field mana pun.
     *
     * @param  array<string, PreparedField>  $fields
     * @return array<string, string|null>
     */
    public function canonicalAttributes(array $fields): array
    {
        $attributes = [
            'document_date' => null,
            'currency' => null,
            'subtotal' => null,
            'tax' => null,
            'total' => null,
        ];

        /*
         * Iterasi mengikuti urutan enum, bukan urutan field yang datang, karena beberapa
         * field memetakan ke kolom yang sama: tanggal dokumen mengalahkan tanggal
         * settlement dan akhir periode, dan total mengalahkan nilai bruto. Urutan enum
         * sudah menyusun keduanya dari yang paling spesifik.
         */
        foreach (DocumentFieldKey::cases() as $key) {
            $column = $key->canonicalColumn();

            if ($column === null || ! isset($fields[$key->value]) || $attributes[$column] !== null) {
                continue;
            }

            $value = $key === DocumentFieldKey::Currency
                ? $this->currency($fields[$key->value]->value())
                : $fields[$key->value]->value();

            $attributes[$column] = $value;
        }

        return $attributes;
    }

    /**
     * Kolom `currency` berupa CHAR(3), jadi hanya kode ISO yang diterima.
     *
     * Nilai lain — "Rp", "rupiah", nama bank — dibuang alih-alih dipotong menjadi tiga
     * huruf pertama. Mata uang yang salah akan membuat seluruh nilai dokumen salah arti,
     * dan menebaknya lebih berbahaya daripada membiarkannya kosong.
     */
    private function currency(string $value): ?string
    {
        $code = mb_strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }
}

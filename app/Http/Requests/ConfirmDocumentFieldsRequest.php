<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentFieldKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reviewer memastikan nilai field hasil ekstraksi (plan.md §16.2).
 *
 * Aturan per field dibangun dari `DocumentFieldKey`, bukan dituliskan satu per satu, supaya
 * tidak ada field yang lolos tanpa aturan ketika extractor baru ditambahkan pada phase
 * berikutnya.
 *
 * Uang dibatasi dua desimal, bukan hanya `numeric`. `numeric` meloloskan notasi eksponen
 * dan desimal panjang, dan keduanya akan dipangkas diam-diam oleh kolom NUMERIC(20,2) —
 * pembulatan tak terlihat justru yang dilarang plan.md §44.14.
 */
class ConfirmDocumentFieldsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'fields' => ['required', 'array', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];

        /** @var array<array-key, mixed> $fields */
        $fields = is_array($this->input('fields')) ? $this->input('fields') : [];

        foreach (array_keys($fields) as $key) {
            $rules['fields.' . $key] = $this->rulesForKey(is_string($key) ? $key : '');
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fields.required' => 'Tidak ada data yang dikonfirmasi.',
            'fields.*.prohibited' => 'Field ini bukan bagian dari data dokumen.',
            'fields.*.regex' => 'Isi nilai uang tanpa pemisah ribuan, maksimal dua desimal.',
            'fields.*.date_format' => 'Isi tanggal dengan format YYYY-MM-DD.',
        ];
    }

    /**
     * Nilai yang dikonfirmasi, selalu sebagai string atau null.
     *
     * @return array<string, string|null>
     */
    public function fieldValues(): array
    {
        /** @var array<array-key, mixed> $fields */
        $fields = is_array($this->input('fields')) ? $this->input('fields') : [];

        $values = [];

        foreach ($fields as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $values[$key] = is_scalar($value) && trim((string) $value) !== ''
                ? trim((string) $value)
                : null;
        }

        return $values;
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * @return array<int, string>
     */
    private function rulesForKey(string $key): array
    {
        $field = DocumentFieldKey::tryFrom($key);

        /*
         * Baris mutasi dikoreksi lewat reconciliation pada Phase 7, bukan lewat formulir
         * field dokumen: satu rekening koran dapat memuat ratusan baris.
         */
        if ($field === null || $field->isRowField()) {
            return ['prohibited'];
        }

        return match ($field->kind()) {
            DocumentFieldKind::Money => ['nullable', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            DocumentFieldKind::Integer => ['nullable', 'integer', 'min:0'],
            DocumentFieldKind::Date => ['nullable', 'date_format:Y-m-d'],
            DocumentFieldKind::Text => ['nullable', 'string', 'max:500'],
        };
    }
}

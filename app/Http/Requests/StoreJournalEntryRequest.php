<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi manual journal entry (plan.md §37 Phase 2 "manual journal dapat dipost").
 *
 * Nilai uang divalidasi sebagai string numerik berskala maksimum dua desimal, bukan
 * `numeric` biasa, agar tidak ada pembulatan float sebelum mencapai domain
 * (plan.md §44.14).
 */
class StoreJournalEntryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'post' => ['nullable', 'boolean'],

            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.credit' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'Journal entry double-entry harus memiliki minimal dua baris.',
            'lines.*.debit.regex' => 'Nilai debit harus angka positif dengan maksimal dua desimal.',
            'lines.*.credit.regex' => 'Nilai kredit harus angka positif dengan maksimal dua desimal.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->input('lines', []);

        $this->merge([
            'lines' => array_map(static function (array $line): array {
                foreach (['debit', 'credit'] as $side) {
                    $value = $line[$side] ?? null;

                    if ($value === null || $value === '') {
                        $line[$side] = '0';

                        continue;
                    }

                    $line[$side] = is_string($value) ? trim($value) : (string) $value;
                }

                return $line;
            }, array_values($lines)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDomainPayload(): array
    {
        return [
            'entry_date' => $this->string('entry_date')->toString(),
            'description' => $this->string('description')->toString(),
            'currency' => $this->input('currency') ?? 'IDR',
            'lines' => $this->input('lines', []),
        ];
    }
}

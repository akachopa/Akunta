<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentFieldKind;
use App\Domain\Documents\Enums\DocumentFieldSource;

/**
 * Satu nilai yang sudah lolos validasi dan siap ditulis ke `document_fields`.
 *
 * Nilainya sudah berada pada kolom yang benar sesuai tipenya. Pemisahan itu terjadi di
 * sini, sebelum menyentuh database, karena constraint `document_fields_value_matches_kind`
 * akan menolak nilai uang yang tersimpan sebagai teks — dan penolakan di tengah transaksi
 * jauh lebih sulit dibaca daripada penolakan pada validator.
 */
final class PreparedField
{
    public function __construct(
        public readonly DocumentFieldKey $key,
        public readonly DocumentFieldKind $kind,
        public readonly ?string $valueText,
        public readonly ?string $valueNumber,
        public readonly ?string $valueDate,
        public readonly string $confidence,
        public readonly ?int $pageNumber,
        public readonly ?string $sourceText,
        public readonly ?int $rowIndex,
    ) {}

    /**
     * Nilai sebagai string, apa pun tipenya. Dipakai pemeriksaan aritmetika dan proyeksi
     * kolom canonical.
     */
    public function value(): string
    {
        return $this->valueText ?? $this->valueNumber ?? $this->valueDate ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'field_key' => $this->key,
            'kind' => $this->kind,
            'row_index' => $this->rowIndex,
            'value_text' => $this->valueText,
            'value_number' => $this->valueNumber,
            'value_date' => $this->valueDate,
            'confidence' => $this->confidence,
            'source' => DocumentFieldSource::Ai,
            'page_number' => $this->pageNumber,
            'source_text' => $this->sourceText,
        ];
    }
}

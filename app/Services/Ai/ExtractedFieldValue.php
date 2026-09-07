<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Documents\Enums\DocumentFieldKey;

/**
 * Satu nilai hasil ekstraksi beserta buktinya (plan.md §14.2, §45.10).
 *
 * `value` selalu string atau null, apa pun tipenya. Uang dan tanggal tidak dikonversi di
 * sini karena konversi memerlukan keputusan yang bukan milik DTO: uang harus lolos
 * validasi format (plan.md §44.14) dan tanggal harus benar-benar ada di kalender. Keduanya
 * dikerjakan validator sebelum nilainya tersimpan.
 */
final class ExtractedFieldValue
{
    public function __construct(
        public readonly DocumentFieldKey $key,
        public readonly ?string $value,
        public readonly string $confidence,
        public readonly ?int $pageNumber,
        public readonly ?string $sourceText,
        public readonly ?int $rowIndex = null,
    ) {}

    public function hasValue(): bool
    {
        return $this->value !== null && $this->value !== '';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Bentuk isi satu halaman hasil parsing (`document_pages`).
 *
 * Nilainya mengikuti keluaran parser di AI worker: satu halaman PDF, satu sheet
 * spreadsheet, satu tabel CSV, atau satu berkas gambar (plan.md §13.1).
 */
enum DocumentPageKind: string
{
    case PdfPage = 'pdf_page';
    case Sheet = 'sheet';
    case Table = 'table';
    case Image = 'image';

    public function isTabular(): bool
    {
        return match ($this) {
            self::Sheet, self::Table => true,
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

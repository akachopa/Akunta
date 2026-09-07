<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Tipe nilai satu field hasil ekstraksi.
 *
 * Tipe menentukan kolom penyimpanannya di `document_fields`, dan pemisahan itu ditegakkan
 * constraint database. Uang wajib berada di kolom NUMERIC (plan.md §44.14) dan tanggal di
 * kolom DATE agar laporan pada phase berikutnya dapat memfilternya tanpa konversi teks.
 */
enum DocumentFieldKind: string
{
    case Text = 'text';
    case Money = 'money';
    case Date = 'date';
    case Integer = 'integer';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Teks',
            self::Money => 'Nilai Uang',
            self::Date => 'Tanggal',
            self::Integer => 'Bilangan',
        };
    }

    /**
     * Kolom `document_fields` tempat nilainya disimpan.
     */
    public function valueColumn(): string
    {
        return match ($this) {
            self::Text => 'value_text',
            self::Money, self::Integer => 'value_number',
            self::Date => 'value_date',
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

<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Hasil satu percobaan ekstraksi.
 *
 * Enum ini adalah penjaga plan.md §37 Phase 4 "invalid output tidak masuk transaction
 * pipeline". Hanya ekstraksi `accepted` yang menghasilkan baris `document_fields`, dan
 * hanya baris itu yang dibaca phase berikutnya. Ekstraksi `rejected` tetap tersimpan
 * lengkap dengan raw output sebagai bukti, tetapi tidak menyisakan satu pun nilai yang
 * dapat disalahartikan sebagai data sah.
 */
enum ExtractionStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Diterima',
            self::Rejected => 'Ditolak',
        };
    }

    /**
     * Boleh menghasilkan nilai field yang dibaca pipeline.
     */
    public function producesFields(): bool
    {
        return $this === self::Accepted;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

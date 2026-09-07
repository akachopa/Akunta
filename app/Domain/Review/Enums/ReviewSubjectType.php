<?php

declare(strict_types=1);

namespace App\Domain\Review\Enums;

/**
 * Subjek antrean Review Center (plan.md §16, §23).
 *
 * Transaksi adalah subjek utama Phase 10. Dokumen yang masih perlu manusia (Phase 4)
 * ikut diantrekan supaya reviewer tidak bolak-balik ke inbox.
 */
enum ReviewSubjectType: string
{
    case Transaction = 'transaction';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Transaction => 'Transaksi',
            self::Document => 'Dokumen',
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

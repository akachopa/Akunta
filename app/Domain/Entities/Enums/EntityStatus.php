<?php

declare(strict_types=1);

namespace App\Domain\Entities\Enums;

/**
 * Status entity master.
 *
 * `merged` berarti entity ini sudah diserap ke entity lain. Barisnya tidak dihapus karena
 * plan.md §31 dan acceptance Phase 6 menuntut riwayat serta jejak merge tetap ada.
 */
enum EntityStatus: string
{
    case Active = 'active';
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Merged => 'Digabung',
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

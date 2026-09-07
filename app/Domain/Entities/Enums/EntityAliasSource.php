<?php

declare(strict_types=1);

namespace App\Domain\Entities\Enums;

/**
 * Asal alias entity (plan.md §9.2, §9.3).
 *
 * Alias yang dikonfirmasi manusia (`user_confirmed`) menjadi pengetahuan bisnis dan
 * dipakai pada transaksi berikutnya — acceptance pertama Phase 6.
 */
enum EntityAliasSource: string
{
    case Extracted = 'extracted';
    case UserConfirmed = 'user_confirmed';
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Extracted => 'Dari Dokumen',
            self::UserConfirmed => 'Dikonfirmasi Pengguna',
            self::Merged => 'Hasil Penggabungan',
        };
    }

    public function isConfirmed(): bool
    {
        return $this === self::UserConfirmed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

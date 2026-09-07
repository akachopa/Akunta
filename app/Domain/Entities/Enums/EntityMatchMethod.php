<?php

declare(strict_types=1);

namespace App\Domain\Entities\Enums;

/**
 * Cara entity dicocokkan ke transaksi (plan.md §9.3).
 */
enum EntityMatchMethod: string
{
    case Identifier = 'identifier';
    case ConfirmedAlias = 'confirmed_alias';
    case NormalizedName = 'normalized_name';
    case Fuzzy = 'fuzzy';
    case Historical = 'historical';
    case Created = 'created';

    public function label(): string
    {
        return match ($this) {
            self::Identifier => 'Identitas unik',
            self::ConfirmedAlias => 'Alias terkonfirmasi',
            self::NormalizedName => 'Nama ternormalisasi',
            self::Fuzzy => 'Kemiripan nama',
            self::Historical => 'Riwayat transaksi',
            self::Created => 'Entity baru',
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

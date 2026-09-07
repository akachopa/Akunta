<?php

declare(strict_types=1);

namespace App\Domain\Business\Enums;

/**
 * plan.md §2.2: basis akrual adalah default.
 */
enum AccountingBasis: string
{
    case Accrual = 'accrual';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Accrual => 'Akrual',
            self::Cash => 'Kas',
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

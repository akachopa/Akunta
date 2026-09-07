<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * plan.md §12.2: COA harus menyimpan normal balance per akun.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return match ($this) {
            self::Debit => self::Credit,
            self::Credit => self::Debit,
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

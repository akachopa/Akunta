<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Sisi baris accounting rule (plan.md §11).
 */
enum JournalLineSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

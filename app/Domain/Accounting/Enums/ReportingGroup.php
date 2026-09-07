<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * plan.md §12.2: COA harus menyimpan reporting group.
 *
 * Group ini menentukan di baris mana akun muncul pada financial statements
 * (plan.md §21.2). Report-nya sendiri baru dibangun pada Phase 12; Phase 2 hanya
 * menyimpan klasifikasinya.
 */
enum ReportingGroup: string
{
    case CurrentAsset = 'current_asset';
    case NonCurrentAsset = 'non_current_asset';
    case CurrentLiability = 'current_liability';
    case NonCurrentLiability = 'non_current_liability';
    case Equity = 'equity';
    case OperatingRevenue = 'operating_revenue';
    case OtherRevenue = 'other_revenue';
    case CostOfSales = 'cost_of_sales';
    case OperatingExpense = 'operating_expense';
    case OtherExpense = 'other_expense';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Tipe akun pada chart of accounts.
 *
 * Diturunkan dari struktur starter COA di plan.md §12.1 yang memisahkan cost of sales
 * dan other expense dari operating expense, karena ketiganya muncul di baris berbeda
 * pada income statement.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case CostOfSales = 'cost_of_sales';
    case OperatingExpense = 'operating_expense';
    case OtherIncome = 'other_income';
    case OtherExpense = 'other_expense';

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::CostOfSales, self::OperatingExpense, self::OtherExpense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue, self::OtherIncome => NormalBalance::Credit,
        };
    }

    /**
     * Akun neraca tidak ditutup ke retained earnings pada akhir periode.
     */
    public function isBalanceSheet(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            default => false,
        };
    }

    public function isProfitAndLoss(): bool
    {
        return ! $this->isBalanceSheet();
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Aset',
            self::Liability => 'Kewajiban',
            self::Equity => 'Ekuitas',
            self::Revenue => 'Pendapatan',
            self::CostOfSales => 'Harga Pokok Penjualan',
            self::OperatingExpense => 'Beban Operasional',
            self::OtherIncome => 'Pendapatan Lain-lain',
            self::OtherExpense => 'Beban Lain-lain',
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

<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\ReportingGroup;
use App\Domain\Business\Enums\BusinessType;

/**
 * Definisi starter chart of accounts (plan.md §12.1).
 *
 * Kode dan nama akun mengikuti struktur starter pada plan.md §12.1. Akun tambahan hanya
 * dibuat ketika taxonomy plan.md §10 atau §19.2 mengharuskannya, dan setiap tambahan
 * diberi catatan alasannya.
 *
 * Akun tingkat satu dan dua yang bersifat kelompok ditandai non-postable supaya saldo
 * tidak tercatat ganda di parent dan child.
 */
final class StarterCoaDefinition
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function for(BusinessType $type): array
    {
        $accounts = self::base($type);

        if (! $type->holdsInventory()) {
            $accounts = array_values(array_filter(
                $accounts,
                static fn (array $account): bool => $account['code'] !== '1300'
            ));
        }

        return array_values(array_map(
            static function (array $account, int $index): array {
                $account['sort_order'] = ($index + 1) * 10;

                return $account;
            },
            $accounts,
            array_keys($accounts)
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function base(BusinessType $type): array
    {
        return [
            self::header('1', 'Aset', AccountType::Asset, ReportingGroup::CurrentAsset),

            self::header('1100', 'Kas & Bank', AccountType::Asset, ReportingGroup::CurrentAsset, '1'),
            self::account('1101', 'Kas', AccountType::Asset, ReportingGroup::CurrentAsset, '1100', AccountRole::CashOrBank),
            self::account('1102', 'Bank', AccountType::Asset, ReportingGroup::CurrentAsset, '1100', AccountRole::CashOrBank),

            self::account('1200', 'Piutang Usaha', AccountType::Asset, ReportingGroup::CurrentAsset, '1', AccountRole::AccountsReceivable),
            self::account('1300', 'Persediaan', AccountType::Asset, ReportingGroup::CurrentAsset, '1', AccountRole::Inventory),
            self::account('1400', 'Beban Dibayar Dimuka', AccountType::Asset, ReportingGroup::CurrentAsset, '1', AccountRole::PrepaidExpense),

            self::header('1500', 'Aset Tetap', AccountType::Asset, ReportingGroup::NonCurrentAsset, '1'),
            self::account('1501', 'Peralatan & Perlengkapan', AccountType::Asset, ReportingGroup::NonCurrentAsset, '1500', AccountRole::FixedAsset),

            // Akun kontra aset: normal balance kredit meskipun account type-nya asset.
            self::account('1590', 'Akumulasi Penyusutan', AccountType::Asset, ReportingGroup::NonCurrentAsset, '1500', AccountRole::AccumulatedDepreciation, NormalBalance::Credit),

            /*
             * plan.md §10.6 mewajibkan transfer internal tidak diperlakukan sebagai
             * income/expense. Akun perantara ini menampung sisi transfer yang belum
             * berpasangan sehingga selisih transfer tidak pernah bocor ke P&L.
             */
            self::account('1900', 'Transfer Antar Rekening (Perantara)', AccountType::Asset, ReportingGroup::CurrentAsset, '1', AccountRole::InternalTransferClearing),

            self::header('2', 'Kewajiban', AccountType::Liability, ReportingGroup::CurrentLiability),
            self::account('2100', 'Hutang Usaha', AccountType::Liability, ReportingGroup::CurrentLiability, '2', AccountRole::AccountsPayable),
            self::account('2200', 'Hutang Pajak', AccountType::Liability, ReportingGroup::CurrentLiability, '2', AccountRole::TaxPayable),
            self::account('2300', 'Hutang Pinjaman', AccountType::Liability, ReportingGroup::NonCurrentLiability, '2', AccountRole::LoanPayable),

            self::header('3', 'Ekuitas', AccountType::Equity, ReportingGroup::Equity),
            self::account('3100', 'Modal Pemilik', AccountType::Equity, ReportingGroup::Equity, '3', AccountRole::OwnerEquity),
            self::account('3200', 'Prive Pemilik', AccountType::Equity, ReportingGroup::Equity, '3', AccountRole::OwnerDrawing),
            self::account('3300', 'Laba Ditahan', AccountType::Equity, ReportingGroup::Equity, '3', AccountRole::RetainedEarnings),

            self::header('4', 'Pendapatan', AccountType::Revenue, ReportingGroup::OperatingRevenue),
            self::account('4100', 'Pendapatan Penjualan', AccountType::Revenue, ReportingGroup::OperatingRevenue, '4', AccountRole::SalesRevenue),
            self::account('4200', 'Pendapatan Jasa', AccountType::Revenue, ReportingGroup::OperatingRevenue, '4', AccountRole::ServiceRevenue),

            /*
             * plan.md §10.1 memuat event SALES_RETURN. Tanpa akun kontra pendapatan,
             * retur penjualan akan terpaksa dicatat sebagai beban. Akun kontra pendapatan,
             * sehingga normal balance-nya debit.
             */
            self::account('4300', 'Retur Penjualan', AccountType::Revenue, ReportingGroup::OperatingRevenue, '4', AccountRole::SalesReturn, NormalBalance::Debit),

            self::account('4900', 'Pendapatan Lain-lain', AccountType::OtherIncome, ReportingGroup::OtherRevenue, '4', AccountRole::OtherIncome),

            self::header('5', 'Harga Pokok Penjualan', AccountType::CostOfSales, ReportingGroup::CostOfSales),
            self::account(
                '5100',
                $type->holdsInventory() ? 'Harga Pokok Penjualan' : 'Beban Pokok Jasa',
                AccountType::CostOfSales,
                ReportingGroup::CostOfSales,
                '5',
                AccountRole::CostOfGoodsSold
            ),

            self::header('6', 'Beban Operasional', AccountType::OperatingExpense, ReportingGroup::OperatingExpense),
            self::account('6100', 'Beban Gaji', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::SalaryExpense),
            self::account('6200', 'Beban Utilitas', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::UtilityExpense),
            self::account('6300', 'Beban Sewa', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::RentExpense),
            self::account('6400', 'Beban Pemasaran', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::MarketingExpense),
            self::account('6500', 'Beban Pengiriman', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::ShippingExpense),
            self::account('6600', 'Beban Kantor & Administrasi', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::OfficeAdminExpense),
            self::account('6700', 'Beban Perbaikan & Pemeliharaan', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::RepairMaintenanceExpense),
            self::account('6800', 'Beban Penyusutan', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::DepreciationExpense),

            /*
             * plan.md §19.2: MDR QRIS harus muncul sebagai beban tersendiri agar
             * settlement bersih tidak dianggap gross revenue.
             */
            self::account('6850', 'Beban Biaya Pembayaran (MDR)', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::PaymentProcessingFee),

            self::account('6900', 'Beban Operasional Lainnya', AccountType::OperatingExpense, ReportingGroup::OperatingExpense, '6', AccountRole::OtherOperatingExpense),

            self::header('7', 'Beban Lain-lain', AccountType::OtherExpense, ReportingGroup::OtherExpense),
            self::account('7100', 'Beban Administrasi Bank', AccountType::OtherExpense, ReportingGroup::OtherExpense, '7', AccountRole::BankCharge),
            self::account('7200', 'Beban Bunga', AccountType::OtherExpense, ReportingGroup::OtherExpense, '7', AccountRole::InterestExpense),
            self::account('7300', 'Beban Pajak', AccountType::OtherExpense, ReportingGroup::OtherExpense, '7', AccountRole::TaxExpense),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function header(
        string $code,
        string $name,
        AccountType $type,
        ReportingGroup $group,
        ?string $parentCode = null,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'parent_code' => $parentCode,
            'account_type' => $type->value,
            'normal_balance' => $type->normalBalance()->value,
            'account_role' => null,
            'reporting_group' => $group->value,
            'is_postable' => false,
            'is_system' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function account(
        string $code,
        string $name,
        AccountType $type,
        ReportingGroup $group,
        ?string $parentCode,
        ?AccountRole $role,
        ?NormalBalance $normalBalance = null,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'parent_code' => $parentCode,
            'account_type' => $type->value,
            'normal_balance' => ($normalBalance ?? $type->normalBalance())->value,
            'account_role' => $role?->value,
            'reporting_group' => $group->value,
            'is_postable' => true,
            'is_system' => true,
        ];
    }
}

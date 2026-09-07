<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * System role akun (plan.md §12.2 "system role").
 *
 * Role inilah yang nanti dirujuk Accounting Rule Engine pada Phase 9
 * (plan.md §11.2–§11.6 memakai `account_role: CASH_OR_BANK`, `SALES_REVENUE`, dst.),
 * sehingga rule tidak pernah menyebut kode akun spesifik milik satu bisnis.
 *
 * Pada Phase 2 role hanya dipakai untuk memetakan COA template ke COA bisnis dan
 * untuk resolusi akun; rule engine sendiri belum dibangun.
 */
enum AccountRole: string
{
    case CashOrBank = 'CASH_OR_BANK';
    case AccountsReceivable = 'ACCOUNTS_RECEIVABLE';
    case Inventory = 'INVENTORY';
    case PrepaidExpense = 'PREPAID_EXPENSE';
    case FixedAsset = 'FIXED_ASSET';
    case AccumulatedDepreciation = 'ACCUMULATED_DEPRECIATION';

    case AccountsPayable = 'ACCOUNTS_PAYABLE';
    case TaxPayable = 'TAX_PAYABLE';
    case LoanPayable = 'LOAN_PAYABLE';

    case OwnerEquity = 'OWNER_EQUITY';
    case OwnerDrawing = 'OWNER_DRAWING';
    case RetainedEarnings = 'RETAINED_EARNINGS';

    case SalesRevenue = 'SALES_REVENUE';
    case ServiceRevenue = 'SERVICE_REVENUE';
    case SalesReturn = 'SALES_RETURN';
    case OtherIncome = 'OTHER_INCOME';

    case CostOfGoodsSold = 'COST_OF_GOODS_SOLD';

    case SalaryExpense = 'SALARY_EXPENSE';
    case UtilityExpense = 'UTILITY_EXPENSE';
    case RentExpense = 'RENT_EXPENSE';
    case MarketingExpense = 'MARKETING_EXPENSE';
    case ShippingExpense = 'SHIPPING_EXPENSE';
    case OfficeAdminExpense = 'OFFICE_ADMIN_EXPENSE';
    case RepairMaintenanceExpense = 'REPAIR_MAINTENANCE_EXPENSE';
    case DepreciationExpense = 'DEPRECIATION_EXPENSE';
    case OtherOperatingExpense = 'OTHER_OPERATING_EXPENSE';

    /**
     * plan.md §19.2: MDR QRIS harus dicatat sebagai beban terpisah, bukan mengurangi
     * gross revenue. Role ini disediakan sejak Phase 2 supaya COA template sudah
     * memuat akunnya.
     */
    case PaymentProcessingFee = 'PAYMENT_PROCESSING_FEE';
    case BankCharge = 'BANK_CHARGE';
    case InterestExpense = 'INTEREST_EXPENSE';
    case TaxExpense = 'TAX_EXPENSE';

    /**
     * Akun perantara transfer internal (plan.md §10.6): dipakai supaya transfer antar
     * rekening tidak pernah menyentuh P&L.
     */
    case InternalTransferClearing = 'INTERNAL_TRANSFER_CLEARING';

    public function expectedAccountType(): AccountType
    {
        return match ($this) {
            self::CashOrBank,
            self::AccountsReceivable,
            self::Inventory,
            self::PrepaidExpense,
            self::FixedAsset,
            self::AccumulatedDepreciation,
            self::InternalTransferClearing => AccountType::Asset,

            self::AccountsPayable,
            self::TaxPayable,
            self::LoanPayable => AccountType::Liability,

            self::OwnerEquity,
            self::OwnerDrawing,
            self::RetainedEarnings => AccountType::Equity,

            self::SalesRevenue,
            self::ServiceRevenue,
            self::SalesReturn => AccountType::Revenue,

            self::OtherIncome => AccountType::OtherIncome,

            self::CostOfGoodsSold => AccountType::CostOfSales,

            self::SalaryExpense,
            self::UtilityExpense,
            self::RentExpense,
            self::MarketingExpense,
            self::ShippingExpense,
            self::OfficeAdminExpense,
            self::RepairMaintenanceExpense,
            self::DepreciationExpense,
            self::PaymentProcessingFee,
            self::OtherOperatingExpense => AccountType::OperatingExpense,

            self::BankCharge,
            self::InterestExpense,
            self::TaxExpense => AccountType::OtherExpense,
        };
    }

    /**
     * Role yang hanya boleh dimiliki satu akun aktif per bisnis, karena rule engine
     * harus dapat me-resolve akun tunggal tanpa ambiguitas.
     */
    public function isUnique(): bool
    {
        return match ($this) {
            self::CashOrBank, self::FixedAsset => false,
            default => true,
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

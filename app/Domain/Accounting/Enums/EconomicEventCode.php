<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Taksonomi economic event (plan.md §10).
 *
 * LLM dan heuristic hanya boleh mengembalikan kode yang terdaftar di sini
 * (plan.md §14.3, §37 Phase 8). Kode karangan tidak boleh menjadi jurnal.
 */
enum EconomicEventCode: string
{
    case SaleCash = 'SALE_CASH';
    case SaleCredit = 'SALE_CREDIT';
    case SaleConsignment = 'SALE_CONSIGNMENT';
    case ReceiveReceivable = 'RECEIVE_RECEIVABLE';
    case SalesReturn = 'SALES_RETURN';
    case OtherOperatingIncome = 'OTHER_OPERATING_INCOME';
    case OtherNonoperatingIncome = 'OTHER_NONOPERATING_INCOME';

    case PurchaseInventoryCash = 'PURCHASE_INVENTORY_CASH';
    case PurchaseInventoryCredit = 'PURCHASE_INVENTORY_CREDIT';
    case PurchaseRawMaterial = 'PURCHASE_RAW_MATERIAL';
    case PurchasePackaging = 'PURCHASE_PACKAGING';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case PaySupplier = 'PAY_SUPPLIER';

    case SalaryExpense = 'SALARY_EXPENSE';
    case UtilityExpense = 'UTILITY_EXPENSE';
    case RentExpense = 'RENT_EXPENSE';
    case MarketingExpense = 'MARKETING_EXPENSE';
    case ShippingExpense = 'SHIPPING_EXPENSE';
    case OfficeSuppliesExpense = 'OFFICE_SUPPLIES_EXPENSE';
    case RepairMaintenanceExpense = 'REPAIR_MAINTENANCE_EXPENSE';
    case BankFee = 'BANK_FEE';
    case InterestExpense = 'INTEREST_EXPENSE';
    case TaxPayment = 'TAX_PAYMENT';
    case OtherOperatingExpense = 'OTHER_OPERATING_EXPENSE';

    case AssetPurchase = 'ASSET_PURCHASE';
    case AssetSale = 'ASSET_SALE';
    case Depreciation = 'DEPRECIATION';
    case PrepaidExpense = 'PREPAID_EXPENSE';

    case OwnerCapital = 'OWNER_CAPITAL';
    case OwnerWithdrawal = 'OWNER_WITHDRAWAL';
    case LoanReceived = 'LOAN_RECEIVED';
    case LoanPrincipalPayment = 'LOAN_PRINCIPAL_PAYMENT';
    case LoanInterestPayment = 'LOAN_INTEREST_PAYMENT';

    case BankTransferInternal = 'BANK_TRANSFER_INTERNAL';
    case CashToBankTransfer = 'CASH_TO_BANK_TRANSFER';
    case BankToCashTransfer = 'BANK_TO_CASH_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::SaleCash => 'Penjualan tunai',
            self::SaleCredit => 'Penjualan kredit',
            self::SaleConsignment => 'Penjualan konsinyasi',
            self::ReceiveReceivable => 'Penerimaan piutang',
            self::SalesReturn => 'Retur penjualan',
            self::OtherOperatingIncome => 'Pendapatan operasional lain',
            self::OtherNonoperatingIncome => 'Pendapatan nonoperasional',
            self::PurchaseInventoryCash => 'Pembelian persediaan tunai',
            self::PurchaseInventoryCredit => 'Pembelian persediaan kredit',
            self::PurchaseRawMaterial => 'Pembelian bahan baku',
            self::PurchasePackaging => 'Pembelian kemasan',
            self::PurchaseReturn => 'Retur pembelian',
            self::PaySupplier => 'Pembayaran pemasok',
            self::SalaryExpense => 'Beban gaji',
            self::UtilityExpense => 'Beban utilitas',
            self::RentExpense => 'Beban sewa',
            self::MarketingExpense => 'Beban pemasaran',
            self::ShippingExpense => 'Beban pengiriman',
            self::OfficeSuppliesExpense => 'Beban perlengkapan kantor',
            self::RepairMaintenanceExpense => 'Beban perbaikan dan pemeliharaan',
            self::BankFee => 'Biaya bank',
            self::InterestExpense => 'Beban bunga',
            self::TaxPayment => 'Pembayaran pajak',
            self::OtherOperatingExpense => 'Beban operasional lain',
            self::AssetPurchase => 'Pembelian aset',
            self::AssetSale => 'Penjualan aset',
            self::Depreciation => 'Penyusutan',
            self::PrepaidExpense => 'Beban dibayar di muka',
            self::OwnerCapital => 'Setoran modal pemilik',
            self::OwnerWithdrawal => 'Penarikan modal pemilik',
            self::LoanReceived => 'Penerimaan pinjaman',
            self::LoanPrincipalPayment => 'Pembayaran pokok pinjaman',
            self::LoanInterestPayment => 'Pembayaran bunga pinjaman',
            self::BankTransferInternal => 'Transfer antar rekening',
            self::CashToBankTransfer => 'Setoran tunai ke bank',
            self::BankToCashTransfer => 'Penarikan tunai dari bank',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::SaleCash, self::SaleCredit, self::SaleConsignment, self::ReceiveReceivable,
            self::SalesReturn, self::OtherOperatingIncome, self::OtherNonoperatingIncome => 'revenue',
            self::PurchaseInventoryCash, self::PurchaseInventoryCredit, self::PurchaseRawMaterial,
            self::PurchasePackaging, self::PurchaseReturn, self::PaySupplier => 'purchases',
            self::SalaryExpense, self::UtilityExpense, self::RentExpense, self::MarketingExpense,
            self::ShippingExpense, self::OfficeSuppliesExpense, self::RepairMaintenanceExpense,
            self::BankFee, self::InterestExpense, self::TaxPayment,
            self::OtherOperatingExpense => 'operating_expense',
            self::AssetPurchase, self::AssetSale, self::Depreciation, self::PrepaidExpense => 'assets',
            self::OwnerCapital, self::OwnerWithdrawal, self::LoanReceived,
            self::LoanPrincipalPayment, self::LoanInterestPayment => 'equity_financing',
            self::BankTransferInternal, self::CashToBankTransfer, self::BankToCashTransfer => 'transfers',
        };
    }

    /**
     * Event yang tidak boleh menyentuh akun pendapatan atau beban (plan.md §10.6, §37 Phase 9).
     */
    public function isNonPl(): bool
    {
        return match ($this) {
            self::BankTransferInternal, self::CashToBankTransfer, self::BankToCashTransfer,
            self::OwnerCapital, self::OwnerWithdrawal, self::LoanReceived,
            self::LoanPrincipalPayment, self::ReceiveReceivable, self::PaySupplier => true,
            default => false,
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

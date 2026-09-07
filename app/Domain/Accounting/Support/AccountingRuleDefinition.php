<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Accounting\Enums\JournalLineSide;
use App\Domain\Accounting\Enums\RuleAmountSource;

/**
 * Pemetaan economic event → baris jurnal (plan.md §11).
 *
 * Rule merujuk account role, bukan kode akun. Internal transfer, setoran modal, dan
 * pokok pinjaman sengaja tidak menyentuh akun pendapatan/beban (plan.md §37 Phase 9).
 */
final class AccountingRuleDefinition
{
    /**
     * @return array<int, array{side: JournalLineSide, role: AccountRole, amount: RuleAmountSource, description: string}>
     */
    public static function lines(EconomicEventCode $event): array
    {
        return match ($event) {
            EconomicEventCode::SaleCash => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::NetAmount, 'Penerimaan kas'),
                self::debit(AccountRole::PaymentProcessingFee, RuleAmountSource::MdrFee, 'Biaya MDR'),
                self::credit(AccountRole::SalesRevenue, RuleAmountSource::GrossAmount, 'Pendapatan penjualan'),
            ],
            EconomicEventCode::SaleCredit => [
                self::debit(AccountRole::AccountsReceivable, RuleAmountSource::Transaction, 'Piutang usaha'),
                self::credit(AccountRole::SalesRevenue, RuleAmountSource::Transaction, 'Pendapatan penjualan'),
            ],
            EconomicEventCode::SaleConsignment => [
                self::debit(AccountRole::AccountsReceivable, RuleAmountSource::Transaction, 'Piutang konsinyasi'),
                self::credit(AccountRole::SalesRevenue, RuleAmountSource::Transaction, 'Pendapatan konsinyasi'),
            ],
            EconomicEventCode::ReceiveReceivable => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Penerimaan piutang'),
                self::credit(AccountRole::AccountsReceivable, RuleAmountSource::Transaction, 'Pelunasan piutang'),
            ],
            EconomicEventCode::SalesReturn => [
                self::debit(AccountRole::SalesReturn, RuleAmountSource::Transaction, 'Retur penjualan'),
                self::credit(AccountRole::AccountsReceivable, RuleAmountSource::Transaction, 'Pengurangan piutang'),
            ],
            EconomicEventCode::OtherOperatingIncome => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Penerimaan'),
                self::credit(AccountRole::OtherIncome, RuleAmountSource::Transaction, 'Pendapatan lain'),
            ],
            EconomicEventCode::OtherNonoperatingIncome => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Penerimaan'),
                self::credit(AccountRole::OtherIncome, RuleAmountSource::Transaction, 'Pendapatan nonoperasional'),
            ],
            EconomicEventCode::PurchaseInventoryCash => [
                self::debit(AccountRole::Inventory, RuleAmountSource::Transaction, 'Pembelian persediaan'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran tunai'),
            ],
            EconomicEventCode::PurchaseInventoryCredit => [
                self::debit(AccountRole::Inventory, RuleAmountSource::Transaction, 'Pembelian persediaan'),
                self::credit(AccountRole::AccountsPayable, RuleAmountSource::Transaction, 'Hutang usaha'),
            ],
            EconomicEventCode::PurchaseRawMaterial => [
                self::debit(AccountRole::Inventory, RuleAmountSource::Transaction, 'Pembelian bahan baku'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran'),
            ],
            EconomicEventCode::PurchasePackaging => [
                self::debit(AccountRole::Inventory, RuleAmountSource::Transaction, 'Pembelian kemasan'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran'),
            ],
            EconomicEventCode::PurchaseReturn => [
                self::debit(AccountRole::AccountsPayable, RuleAmountSource::Transaction, 'Pengurangan hutang'),
                self::credit(AccountRole::Inventory, RuleAmountSource::Transaction, 'Retur pembelian'),
            ],
            EconomicEventCode::PaySupplier => [
                self::debit(AccountRole::AccountsPayable, RuleAmountSource::Transaction, 'Pelunasan hutang'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran pemasok'),
            ],
            EconomicEventCode::SalaryExpense => [
                self::debit(AccountRole::SalaryExpense, RuleAmountSource::Transaction, 'Beban gaji'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran gaji'),
            ],
            EconomicEventCode::UtilityExpense => [
                self::debit(AccountRole::UtilityExpense, RuleAmountSource::Transaction, 'Beban utilitas'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran utilitas'),
            ],
            EconomicEventCode::RentExpense => [
                self::debit(AccountRole::RentExpense, RuleAmountSource::Transaction, 'Beban sewa'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran sewa'),
            ],
            EconomicEventCode::MarketingExpense => [
                self::debit(AccountRole::MarketingExpense, RuleAmountSource::Transaction, 'Beban pemasaran'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran pemasaran'),
            ],
            EconomicEventCode::ShippingExpense => [
                self::debit(AccountRole::ShippingExpense, RuleAmountSource::Transaction, 'Beban pengiriman'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran pengiriman'),
            ],
            EconomicEventCode::OfficeSuppliesExpense => [
                self::debit(AccountRole::OfficeAdminExpense, RuleAmountSource::Transaction, 'Beban perlengkapan'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran perlengkapan'),
            ],
            EconomicEventCode::RepairMaintenanceExpense => [
                self::debit(AccountRole::RepairMaintenanceExpense, RuleAmountSource::Transaction, 'Beban perbaikan'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran perbaikan'),
            ],
            EconomicEventCode::BankFee => [
                self::debit(AccountRole::BankCharge, RuleAmountSource::Transaction, 'Biaya bank'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pemotongan rekening'),
            ],
            EconomicEventCode::InterestExpense => [
                self::debit(AccountRole::InterestExpense, RuleAmountSource::Transaction, 'Beban bunga'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran bunga'),
            ],
            EconomicEventCode::TaxPayment => [
                self::debit(AccountRole::TaxPayable, RuleAmountSource::Transaction, 'Pelunasan pajak'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran pajak'),
            ],
            EconomicEventCode::OtherOperatingExpense => [
                self::debit(AccountRole::OtherOperatingExpense, RuleAmountSource::Transaction, 'Beban operasional'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran'),
            ],
            EconomicEventCode::AssetPurchase => [
                self::debit(AccountRole::FixedAsset, RuleAmountSource::Transaction, 'Pembelian aset'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran aset'),
            ],
            EconomicEventCode::AssetSale => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Penerimaan penjualan aset'),
                self::credit(AccountRole::FixedAsset, RuleAmountSource::Transaction, 'Pengurangan aset'),
            ],
            EconomicEventCode::Depreciation => [
                self::debit(AccountRole::DepreciationExpense, RuleAmountSource::Transaction, 'Beban penyusutan'),
                self::credit(AccountRole::AccumulatedDepreciation, RuleAmountSource::Transaction, 'Akumulasi penyusutan'),
            ],
            EconomicEventCode::PrepaidExpense => [
                self::debit(AccountRole::PrepaidExpense, RuleAmountSource::Transaction, 'Beban dibayar di muka'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran di muka'),
            ],
            EconomicEventCode::OwnerCapital => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Setoran modal'),
                self::credit(AccountRole::OwnerEquity, RuleAmountSource::Transaction, 'Modal pemilik'),
            ],
            EconomicEventCode::OwnerWithdrawal => [
                self::debit(AccountRole::OwnerDrawing, RuleAmountSource::Transaction, 'Penarikan modal'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pengeluaran ke pemilik'),
            ],
            EconomicEventCode::LoanReceived => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Penerimaan pinjaman'),
                self::credit(AccountRole::LoanPayable, RuleAmountSource::Transaction, 'Hutang pinjaman'),
            ],
            EconomicEventCode::LoanPrincipalPayment => [
                self::debit(AccountRole::LoanPayable, RuleAmountSource::Transaction, 'Pengurangan pokok pinjaman'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran pokok'),
            ],
            EconomicEventCode::LoanInterestPayment => [
                self::debit(AccountRole::InterestExpense, RuleAmountSource::Transaction, 'Beban bunga pinjaman'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Pembayaran bunga'),
            ],
            EconomicEventCode::BankTransferInternal => [
                self::debit(AccountRole::InternalTransferClearing, RuleAmountSource::Transaction, 'Kliring transfer internal'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Keluar dari rekening'),
            ],
            EconomicEventCode::CashToBankTransfer => [
                self::debit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Masuk ke rekening'),
                self::credit(AccountRole::InternalTransferClearing, RuleAmountSource::Transaction, 'Kliring setoran tunai'),
            ],
            EconomicEventCode::BankToCashTransfer => [
                self::debit(AccountRole::InternalTransferClearing, RuleAmountSource::Transaction, 'Kliring penarikan tunai'),
                self::credit(AccountRole::CashOrBank, RuleAmountSource::Transaction, 'Keluar dari rekening'),
            ],
        };
    }

    /**
     * @return array{side: JournalLineSide, role: AccountRole, amount: RuleAmountSource, description: string}
     */
    private static function debit(AccountRole $role, RuleAmountSource $amount, string $description): array
    {
        return [
            'side' => JournalLineSide::Debit,
            'role' => $role,
            'amount' => $amount,
            'description' => $description,
        ];
    }

    /**
     * @return array{side: JournalLineSide, role: AccountRole, amount: RuleAmountSource, description: string}
     */
    private static function credit(AccountRole $role, RuleAmountSource $amount, string $description): array
    {
        return [
            'side' => JournalLineSide::Credit,
            'role' => $role,
            'amount' => $amount,
            'description' => $description,
        ];
    }
}

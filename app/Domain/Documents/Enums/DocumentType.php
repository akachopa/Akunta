<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Document type per plan.md §7.1.
 *
 * Pada Phase 3 nilai ini hanya terisi bila user memberi petunjuk saat upload. plan.md
 * §5.2 menegaskan user tidak wajib menentukan jenis dokumen, sehingga kolomnya nullable
 * dan pengisian otomatis menjadi tugas document classifier pada Phase 4.
 */
enum DocumentType: string
{
    case BankStatement = 'bank_statement';
    case SalesInvoice = 'sales_invoice';
    case PurchaseInvoice = 'purchase_invoice';
    case Receipt = 'receipt';
    case CashReceipt = 'cash_receipt';
    case CashDisbursement = 'cash_disbursement';
    case TransferProof = 'transfer_proof';
    case QrisSettlement = 'qris_settlement';
    case EwalletSettlement = 'ewallet_settlement';
    case PosReport = 'pos_report';
    case MarketplaceReport = 'marketplace_report';
    case ConsignmentReport = 'consignment_report';
    case ReceivablePaymentReceipt = 'receivable_payment_receipt';
    case LoanAgreement = 'loan_agreement';
    case CapitalDeposit = 'capital_deposit';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case Payroll = 'payroll';
    case UtilityBill = 'utility_bill';
    case AdvertisingInvoice = 'advertising_invoice';
    case ShippingReceipt = 'shipping_receipt';
    case RentReceipt = 'rent_receipt';
    case RepairReceipt = 'repair_receipt';
    case TaxDocument = 'tax_document';
    case InventoryReport = 'inventory_report';
    case Other = 'other';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::BankStatement => 'Rekening Koran / Mutasi Bank',
            self::SalesInvoice => 'Invoice Penjualan',
            self::PurchaseInvoice => 'Invoice Pembelian',
            self::Receipt => 'Struk / Kwitansi',
            self::CashReceipt => 'Bukti Penerimaan Kas',
            self::CashDisbursement => 'Bukti Pengeluaran Kas',
            self::TransferProof => 'Bukti Transfer',
            self::QrisSettlement => 'Settlement QRIS',
            self::EwalletSettlement => 'Settlement E-Wallet',
            self::PosReport => 'Laporan POS',
            self::MarketplaceReport => 'Laporan Marketplace',
            self::ConsignmentReport => 'Laporan Konsinyasi',
            self::ReceivablePaymentReceipt => 'Bukti Pelunasan Piutang',
            self::LoanAgreement => 'Perjanjian Pinjaman',
            self::CapitalDeposit => 'Bukti Setoran Modal',
            self::PurchaseOrder => 'Purchase Order',
            self::GoodsReceipt => 'Bukti Terima Barang',
            self::Payroll => 'Daftar Gaji',
            self::UtilityBill => 'Tagihan Utilitas',
            self::AdvertisingInvoice => 'Invoice Iklan',
            self::ShippingReceipt => 'Bukti Pengiriman',
            self::RentReceipt => 'Bukti Sewa',
            self::RepairReceipt => 'Bukti Perbaikan',
            self::TaxDocument => 'Dokumen Pajak',
            self::InventoryReport => 'Laporan Persediaan',
            self::Other => 'Lainnya',
            self::Unknown => 'Belum Diketahui',
        };
    }

    /**
     * Tipe yang boleh dipilih user saat upload.
     *
     * `unknown` dikecualikan karena itu hasil klasifikasi, bukan pernyataan user.
     *
     * @return array<int, self>
     */
    public static function selectableOnUpload(): array
    {
        return array_filter(
            self::cases(),
            static fn (self $case): bool => $case !== self::Unknown
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\EconomicEvents;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionRelationType;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionRelation;

/**
 * Heuristic klasifikasi economic event (plan.md §10, §14.3).
 *
 * Mapping deterministik dari jenis dokumen, arah uang, dan dokumen terkait. Worker AI
 * hanya dipanggil ketika heuristic tidak yakin, sesuai plan.md §13.2.
 */
final class EconomicEventHeuristic
{
    public function classify(Transaction $transaction): HeuristicClassification
    {
        $documentType = $transaction->sourceDocument?->document_type;
        $description = mb_strtolower($transaction->description);
        $related = $this->hasRelated($transaction);

        if ($this->mentions($description, ['biaya admin', 'biaya bank', 'admin bank', 'monthly fee'])) {
            return $this->hit(EconomicEventCode::BankFee, '0.96', 'Keterangan memuat biaya administrasi bank.');
        }

        if ($this->mentions($description, ['setoran tunai', 'setor tunai', 'deposit tunai'])) {
            return $this->hit(EconomicEventCode::CashToBankTransfer, '0.96', 'Keterangan memuat setoran tunai ke rekening.');
        }

        if ($this->mentions($description, ['tarik tunai', 'penarikan tunai', 'atm withdrawal'])) {
            return $this->hit(EconomicEventCode::BankToCashTransfer, '0.96', 'Keterangan memuat penarikan tunai.');
        }

        if ($this->mentions($description, ['transfer antar rekening', 'pindah buku', 'internal transfer'])) {
            return $this->hit(EconomicEventCode::BankTransferInternal, '0.96', 'Keterangan memuat transfer internal.');
        }

        return match ($documentType) {
            DocumentType::QrisSettlement, DocumentType::EwalletSettlement, DocumentType::PosReport,
            DocumentType::MarketplaceReport => $this->hit(
                EconomicEventCode::SaleCash,
                '0.96',
                'Settlement atau laporan penjualan tunai.'
            ),
            DocumentType::SalesInvoice => $this->hit(
                $related ? EconomicEventCode::SaleCash : EconomicEventCode::SaleCredit,
                $related ? '0.94' : '0.96',
                $related ? 'Faktur penjualan yang sudah tertaut dengan pembayaran.' : 'Faktur penjualan tanpa pembayaran tertaut.'
            ),
            DocumentType::PurchaseInvoice => $this->hit(
                $related ? EconomicEventCode::PurchaseInventoryCash : EconomicEventCode::PurchaseInventoryCredit,
                $related ? '0.94' : '0.96',
                $related ? 'Faktur pembelian yang sudah tertaut dengan pembayaran.' : 'Faktur pembelian tanpa pembayaran tertaut.'
            ),
            DocumentType::ConsignmentReport => $this->hit(EconomicEventCode::SaleConsignment, '0.95', 'Laporan konsinyasi.'),
            DocumentType::Receipt => $this->expenseFromReceipt($transaction, $description),
            DocumentType::UtilityBill => $this->hit(EconomicEventCode::UtilityExpense, '0.96', 'Tagihan utilitas.'),
            DocumentType::Payroll => $this->hit(EconomicEventCode::SalaryExpense, '0.96', 'Dokumen gaji.'),
            DocumentType::AdvertisingInvoice => $this->hit(EconomicEventCode::MarketingExpense, '0.96', 'Faktur iklan.'),
            DocumentType::ShippingReceipt => $this->hit(EconomicEventCode::ShippingExpense, '0.96', 'Bukti pengiriman.'),
            DocumentType::RentReceipt => $this->hit(EconomicEventCode::RentExpense, '0.96', 'Bukti sewa.'),
            DocumentType::RepairReceipt => $this->hit(EconomicEventCode::RepairMaintenanceExpense, '0.96', 'Bukti perbaikan.'),
            DocumentType::TaxDocument => $this->hit(EconomicEventCode::TaxPayment, '0.93', 'Dokumen pajak.'),
            DocumentType::CapitalDeposit => $this->hit(EconomicEventCode::OwnerCapital, '0.96', 'Setoran modal.'),
            DocumentType::LoanAgreement => $this->loan($transaction),
            DocumentType::ReceivablePaymentReceipt, DocumentType::CashReceipt => $this->hit(
                EconomicEventCode::ReceiveReceivable,
                '0.94',
                'Bukti penerimaan piutang.'
            ),
            DocumentType::TransferProof => $this->transferProof($transaction, $related),
            DocumentType::BankStatement => $this->bankRow($transaction, $related, $description),
            default => $this->fallback($transaction),
        };
    }

    private function expenseFromReceipt(Transaction $transaction, string $description): HeuristicClassification
    {
        if ($this->mentions($description, ['listrik', 'air', 'internet', 'pln', 'pdam', 'telkom'])) {
            return $this->hit(EconomicEventCode::UtilityExpense, '0.93', 'Struk memuat tagihan utilitas.');
        }

        if ($this->mentions($description, ['sewa', 'rent'])) {
            return $this->hit(EconomicEventCode::RentExpense, '0.93', 'Struk memuat sewa.');
        }

        if ($transaction->direction === TransactionDirection::Outflow) {
            return $this->hit(
                EconomicEventCode::OtherOperatingExpense,
                '0.88',
                'Struk pengeluaran yang jenis bebannya belum spesifik.',
                ['Jenis beban pada struk ini belum dapat dipastikan.']
            );
        }

        return $this->hit(
            EconomicEventCode::OtherOperatingIncome,
            '0.80',
            'Struk penerimaan yang jenis pendapatannya belum spesifik.',
            ['Jenis pendapatan pada struk ini belum dapat dipastikan.']
        );
    }

    private function loan(Transaction $transaction): HeuristicClassification
    {
        if ($transaction->direction === TransactionDirection::Inflow) {
            return $this->hit(EconomicEventCode::LoanReceived, '0.95', 'Penerimaan dana dari perjanjian pinjaman.');
        }

        return $this->hit(EconomicEventCode::LoanPrincipalPayment, '0.88', 'Pengeluaran terkait perjanjian pinjaman.', [
            'Belum dapat dipastikan apakah ini pokok atau bunga pinjaman.',
        ]);
    }

    private function transferProof(Transaction $transaction, bool $related): HeuristicClassification
    {
        if ($related) {
            return $transaction->direction === TransactionDirection::Outflow
                ? $this->hit(EconomicEventCode::PaySupplier, '0.94', 'Bukti transfer tertaut dengan faktur pembelian.')
                : $this->hit(EconomicEventCode::ReceiveReceivable, '0.94', 'Bukti transfer tertaut dengan faktur penjualan.');
        }

        return $this->fallback($transaction);
    }

    private function bankRow(Transaction $transaction, bool $related, string $description): HeuristicClassification
    {
        if ($related) {
            return $transaction->direction === TransactionDirection::Outflow
                ? $this->hit(EconomicEventCode::PaySupplier, '0.93', 'Mutasi keluar tertaut dengan faktur pembelian.')
                : $this->hit(EconomicEventCode::ReceiveReceivable, '0.93', 'Mutasi masuk tertaut dengan faktur penjualan.');
        }

        if ($transaction->source_type === TransactionSourceType::BankStatement && $this->mentions($description, ['qris', 'settlement'])) {
            return $this->hit(EconomicEventCode::SaleCash, '0.90', 'Mutasi menyerupai settlement pembayaran.');
        }

        return $this->fallback($transaction);
    }

    private function fallback(Transaction $transaction): HeuristicClassification
    {
        if ($transaction->direction === TransactionDirection::Inflow) {
            return $this->hit(
                EconomicEventCode::OtherOperatingIncome,
                '0.62',
                'Arah uang masuk, tetapi jenis peristiwanya belum dapat dipastikan.',
                ['Jenis peristiwa ekonomi untuk penerimaan ini belum dapat dipastikan.']
            );
        }

        return $this->hit(
            EconomicEventCode::OtherOperatingExpense,
            '0.62',
            'Arah uang keluar, tetapi jenis peristiwanya belum dapat dipastikan.',
            ['Jenis peristiwa ekonomi untuk pengeluaran ini belum dapat dipastikan.']
        );
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasRelated(Transaction $transaction): bool
    {
        return TransactionRelation::query()
            ->where('type', TransactionRelationType::Related->value)
            ->where(function ($query) use ($transaction): void {
                $query->where('from_transaction_id', $transaction->getKey())
                    ->orWhere('to_transaction_id', $transaction->getKey());
            })
            ->exists();
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function hit(EconomicEventCode $code, string $confidence, string $reason, array $missing = []): HeuristicClassification
    {
        return new HeuristicClassification($code, $confidence, $reason, $missing);
    }
}

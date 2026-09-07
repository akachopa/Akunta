<?php

declare(strict_types=1);

namespace App\Services\TransactionMatching;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionRelationType;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionRelation;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Pencocokan dokumen terkait (plan.md §18.2, §37 Phase 7).
 *
 * Dua bukti berbeda yang mendukung peristiwa yang sama, misalnya faktur pembelian dan
 * bukti transfer. Keduanya tetap menghasilkan transaksi; yang dihubungkan adalah
 * hubungannya, bukan identitasnya.
 */
class RelatedDocumentMatcher
{
    /**
     * Jendela pembayaran default, dalam hari (plan.md §18.3 payment window).
     */
    private const PAYMENT_WINDOW_DAYS = 14;

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{from: Transaction, to: Transaction, confidence: string, reasons: array<int, string>}>
     */
    public function match(Collection $transactions): array
    {
        $matches = [];
        $seen = [];

        foreach ($transactions as $transaction) {
            $partners = Transaction::query()
                ->where('id', '!=', $transaction->getKey())
                ->where('business_id', $transaction->business_id)
                ->where('amount', $transaction->amount)
                ->whereBetween('transaction_date', [
                    $transaction->transaction_date->copy()->subDays(self::PAYMENT_WINDOW_DAYS)->toDateString(),
                    $transaction->transaction_date->copy()->addDays(self::PAYMENT_WINDOW_DAYS)->toDateString(),
                ])
                ->with('source.document')
                ->get();

            foreach ($partners as $partner) {
                $pairKey = collect([$transaction->getKey(), $partner->getKey()])->sort()->implode(':');

                if (isset($seen[$pairKey]) || $this->alreadyLinked($transaction, $partner)) {
                    continue;
                }

                $reasons = $this->reasons($transaction, $partner);

                if ($reasons === []) {
                    continue;
                }

                $seen[$pairKey] = true;
                $matches[] = [
                    'from' => $transaction,
                    'to' => $partner,
                    'confidence' => $this->confidence($reasons),
                    'reasons' => $reasons,
                ];
            }
        }

        return $matches;
    }

    /**
     * @return array<int, string>
     */
    private function reasons(Transaction $left, Transaction $right): array
    {
        if ($left->source_type === $right->source_type) {
            return [];
        }

        $reasons = [];

        if (Money::equals($left->amount, $right->amount)) {
            $reasons[] = 'Nominal sama.';
        }

        if ($this->isInvoicePaymentPair($left, $right)) {
            $reasons[] = 'Faktur dan pembayaran.';
        }

        if (
            $left->counterparty_entity_id !== null
            && $left->counterparty_entity_id === $right->counterparty_entity_id
        ) {
            $reasons[] = 'Pihak lawan sama.';
        }

        $number = $this->documentNumber($left->sourceDocument) ?? $this->documentNumber($right->sourceDocument);

        if ($number !== null && (str_contains(mb_strtolower($left->description), $number) || str_contains(mb_strtolower($right->description), $number))) {
            $reasons[] = sprintf('Nomor dokumen %s muncul pada keterangan.', $number);
        }

        if (count($reasons) < 2) {
            return [];
        }

        return $reasons;
    }

    private function isInvoicePaymentPair(Transaction $left, Transaction $right): bool
    {
        $invoiceTypes = [
            TransactionSourceType::Invoice,
            TransactionSourceType::Receipt,
        ];
        $paymentTypes = [
            TransactionSourceType::BankStatement,
            TransactionSourceType::Settlement,
        ];

        $leftInvoice = in_array($left->source_type, $invoiceTypes, true);
        $rightInvoice = in_array($right->source_type, $invoiceTypes, true);
        $leftPayment = in_array($left->source_type, $paymentTypes, true);
        $rightPayment = in_array($right->source_type, $paymentTypes, true);

        if (! (($leftInvoice && $rightPayment) || ($rightInvoice && $leftPayment))) {
            return false;
        }

        $invoice = $leftInvoice ? $left : $right;
        $payment = $leftPayment ? $left : $right;
        $documentType = $invoice->sourceDocument?->document_type;

        if ($documentType === DocumentType::PurchaseInvoice) {
            return $payment->direction === TransactionDirection::Outflow;
        }

        if ($documentType === DocumentType::SalesInvoice) {
            return $payment->direction === TransactionDirection::Inflow;
        }

        return true;
    }

    private function documentNumber(?Document $document): ?string
    {
        if (! $document instanceof Document) {
            return null;
        }

        $field = $document->fields()
            ->where('field_key', 'document_number')
            ->whereNull('row_index')
            ->first();

        $value = $field?->displayValue();

        return is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : null;
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function confidence(array $reasons): string
    {
        return count($reasons) >= 3 ? '0.95' : '0.88';
    }

    private function alreadyLinked(Transaction $from, Transaction $to): bool
    {
        return TransactionRelation::query()
            ->whereIn('type', [
                TransactionRelationType::Related->value,
                TransactionRelationType::Duplicate->value,
            ])
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($inner) use ($from, $to): void {
                    $inner->where('from_transaction_id', $from->getKey())
                        ->where('to_transaction_id', $to->getKey());
                })->orWhere(function ($inner) use ($from, $to): void {
                    $inner->where('from_transaction_id', $to->getKey())
                        ->where('to_transaction_id', $from->getKey());
                });
            })
            ->exists();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\TransactionMatching;

use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Enums\TransactionRelationType;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionRelation;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Deteksi duplikat (plan.md §18.1, §37 Phase 7).
 *
 * Dua bukti untuk peristiwa yang sama. Yang lebih baru ditandai duplikat supaya tidak
 * menghasilkan jurnal kedua (acceptance: "duplicate tidak menghasilkan double transaction").
 */
class DuplicateDetector
{
    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{from: Transaction, to: Transaction, confidence: string, reasons: array<int, string>}>
     */
    public function detect(Collection $transactions): array
    {
        $matches = [];
        $seen = [];

        foreach ($transactions as $transaction) {
            $document = $transaction->sourceDocument;

            $candidates = Transaction::query()
                ->where('id', '!=', $transaction->getKey())
                ->where('business_id', $transaction->business_id)
                ->where('amount', $transaction->amount)
                ->where('direction', $transaction->direction->value)
                ->whereBetween('transaction_date', [
                    $transaction->transaction_date->copy()->subDays(3)->toDateString(),
                    $transaction->transaction_date->copy()->addDays(3)->toDateString(),
                ])
                ->with('source.document')
                ->get();

            foreach ($candidates as $candidate) {
                $pairKey = $this->pairKey($transaction, $candidate);

                if (isset($seen[$pairKey])) {
                    continue;
                }

                if ($this->alreadyLinked($transaction, $candidate, TransactionRelationType::Duplicate)) {
                    continue;
                }

                $reasons = $this->reasons($transaction, $candidate, $document);

                if ($reasons === []) {
                    continue;
                }

                $seen[$pairKey] = true;
                $matches[] = [
                    'from' => $transaction,
                    'to' => $candidate,
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
    private function reasons(Transaction $left, Transaction $right, ?Document $leftDocument): array
    {
        $reasons = [];
        $rightDocument = $right->sourceDocument;

        if (
            $leftDocument instanceof Document
            && $rightDocument instanceof Document
            && $leftDocument->checksum_sha256 === $rightDocument->checksum_sha256
        ) {
            $reasons[] = 'Berkas sumber memiliki hash yang sama.';
        }

        $leftNumber = $this->documentNumber($leftDocument);
        $rightNumber = $this->documentNumber($rightDocument);

        if ($leftNumber !== null && $leftNumber === $rightNumber) {
            $reasons[] = sprintf('Nomor dokumen sama: %s.', $leftNumber);
        }

        if (
            $left->counterparty_entity_id !== null
            && $left->counterparty_entity_id === $right->counterparty_entity_id
            && Money::equals($left->amount, $right->amount)
            && $left->transaction_date->equalTo($right->transaction_date)
            && $left->source_type === $right->source_type
        ) {
            $reasons[] = 'Nominal, tanggal, jenis sumber, dan pihak lawan sama.';
        }

        if (
            mb_strtolower(trim($left->description)) === mb_strtolower(trim($right->description))
            && Money::equals($left->amount, $right->amount)
            && $left->transaction_date->equalTo($right->transaction_date)
            && $left->created_at >= $right->created_at
        ) {
            $reasons[] = 'Deskripsi, nominal, dan tanggal sama.';
        }

        return $reasons;
    }

    private function documentNumber(?Document $document): ?string
    {
        if ($document?->acceptedExtraction === null) {
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
        return count($reasons) >= 2 ? '0.97' : '0.86';
    }

    private function alreadyLinked(Transaction $from, Transaction $to, TransactionRelationType $type): bool
    {
        return TransactionRelation::query()
            ->where('type', $type->value)
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

    private function pairKey(Transaction $left, Transaction $right): string
    {
        $ids = [$left->getKey(), $right->getKey()];
        sort($ids);

        return implode(':', $ids);
    }
}

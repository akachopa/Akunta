<?php

declare(strict_types=1);

namespace App\Services\TransactionMatching;

use App\Domain\Transactions\Enums\TransactionRelationType;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionRelation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Orkestrasi duplicate dan related matching (plan.md §18, §37 Phase 7).
 *
 * Duplicate dicek lebih dulu. Transaksi yang sudah dinyatakan duplikat tidak di-link
 * sebagai related, supaya kedua konsep tidak tercampur (plan.md §44.9).
 */
class TransactionMatchingService
{
    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly RelatedDocumentMatcher $related,
    ) {}

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{duplicates: int, related: int, duplicate_ids: array<int, string>}
     */
    public function match(Collection $transactions): array
    {
        $duplicateIds = [];
        $duplicateCount = 0;
        $relatedCount = 0;

        foreach ($this->duplicates->detect($transactions) as $match) {
            $this->link(
                $match['from'],
                $match['to'],
                TransactionRelationType::Duplicate,
                $match['confidence'],
                $match['reasons'],
            );

            $duplicateIds[] = $this->duplicateOf($match['from'], $match['to'])->getKey();
            $duplicateCount++;
        }

        $remaining = $transactions->reject(
            static fn (Transaction $transaction): bool => in_array($transaction->getKey(), $duplicateIds, true)
        );

        foreach ($this->related->match($remaining) as $match) {
            $this->link(
                $match['from'],
                $match['to'],
                TransactionRelationType::Related,
                $match['confidence'],
                $match['reasons'],
            );
            $relatedCount++;
        }

        return [
            'duplicates' => $duplicateCount,
            'related' => $relatedCount,
            'duplicate_ids' => array_values(array_unique($duplicateIds)),
        ];
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function link(
        Transaction $from,
        Transaction $to,
        TransactionRelationType $type,
        string $confidence,
        array $reasons,
    ): void {
        $exists = TransactionRelation::query()
            ->where('from_transaction_id', $from->getKey())
            ->where('to_transaction_id', $to->getKey())
            ->where('type', $type->value)
            ->exists();

        if ($exists) {
            return;
        }

        TransactionRelation::query()->create([
            'business_id' => $from->business_id,
            'from_transaction_id' => $from->getKey(),
            'to_transaction_id' => $to->getKey(),
            'type' => $type,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Transaksi yang lebih baru yang ditandai duplikat dan tidak dijurnal ulang.
     *
     * `>=` penting: tes dan unggahan beruntun sering jatuh pada detik yang sama, dan
     * perbandingan ketat `>` akan memilih transaksi lama — sehingga yang baru tetap
     * dijurnal (double transaction, plan.md §37 Phase 7).
     *
     * `from` adalah transaksi dokumen yang sedang diproses. Bila timestamp sama, itulah
     * yang ditandai duplikat.
     */
    private function duplicateOf(Transaction $from, Transaction $to): Transaction
    {
        return $from->created_at >= $to->created_at ? $from : $to;
    }
}

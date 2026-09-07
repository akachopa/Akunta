<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Accounting\Models\EconomicEventType;
use App\Domain\Documents\Models\Document;
use App\Domain\Entities\Models\Entity;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionEvidence;
use App\Domain\Transactions\Models\TransactionRelation;
use App\Domain\Transactions\Models\TransactionSource;

/**
 * Bentuk payload transaksi untuk web (Inertia) dan API v1.
 *
 * Nilai uang tetap string. Mengubahnya menjadi number JSON akan mengembalikan float yang
 * justru dihindari plan.md §44.14, dan pembulatannya baru terlihat setelah angkanya
 * dijumlahkan di tempat lain.
 *
 * Sumber dan bukti selalu ikut pada detail, tidak pernah opsional. Acceptance Phase 5
 * menuntut setiap transaksi dapat ditelusuri ke sumbernya, dan traceability yang harus
 * diminta lewat parameter tambahan adalah traceability yang tidak akan dipakai.
 */
class TransactionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Transaction $transaction): array
    {
        return [
            'id' => $transaction->getKey(),
            'reference' => $transaction->reference,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'posting_date' => $transaction->posting_date?->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'direction' => $transaction->direction->value,
            'direction_label' => $transaction->direction->label(),
            'currency' => $transaction->currency,
            'counterparty_name' => $transaction->counterparty_name,
            'counterparty_entity' => $transaction->counterpartyEntity instanceof Entity ? [
                'id' => $transaction->counterpartyEntity->getKey(),
                'name' => $transaction->counterpartyEntity->name,
                'type' => $transaction->counterpartyEntity->type->value,
                'type_label' => $transaction->counterpartyEntity->type->label(),
                'confirmed' => $transaction->counterpartyEntity->isConfirmed(),
            ] : null,
            'economic_event' => $transaction->economicEvent instanceof EconomicEventType ? [
                'id' => $transaction->economicEvent->getKey(),
                'code' => $transaction->economicEvent->code,
                'name' => $transaction->economicEvent->name,
            ] : null,
            'source_type' => $transaction->source_type->value,
            'source_type_label' => $transaction->source_type->label(),
            'status' => $transaction->status->value,
            'status_label' => $transaction->status->label(),
            'needs_attention' => $transaction->status->needsAttention(),
            'overall_confidence' => $transaction->overall_confidence,
            'review_reason' => $transaction->review_reason,
            'normalized_at' => $transaction->normalized_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Transaction $transaction): array
    {
        return $this->summary($transaction) + [
            'source' => $transaction->source instanceof TransactionSource
                ? $this->source($transaction->source)
                : null,
            'evidence' => $transaction->evidence
                ->map(fn (TransactionEvidence $evidence): array => $this->evidence($evidence))
                ->all(),
            'relations' => $this->relations($transaction),
            'journal' => $this->journal($transaction),
            'event_options' => array_map(
                static fn (EconomicEventCode $code): array => [
                    'value' => $code->value,
                    'label' => $code->label(),
                ],
                EconomicEventCode::cases()
            ),
        ];
    }

    /**
     * Asal transaksi sampai ke dokumen dan unit sumbernya (plan.md §23.5).
     *
     * @return array<string, mixed>
     */
    public function source(TransactionSource $source): array
    {
        $document = $source->document;

        return [
            'source_type' => $source->source_type->value,
            'source_type_label' => $source->source_type->label(),
            'source_reference' => $source->source_reference,
            'row_index' => $source->row_index,
            'page_number' => $source->page_number,
            'document' => $document instanceof Document ? [
                'id' => $document->getKey(),
                'reference' => $document->reference,
                'original_filename' => $document->original_filename,
                'document_type' => $document->document_type?->value,
                'document_type_label' => $document->document_type?->label(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evidence(TransactionEvidence $evidence): array
    {
        return [
            'id' => $evidence->getKey(),
            'type' => $evidence->type->value,
            'type_label' => $evidence->type->label(),
            'document_id' => $evidence->document_id,
            'row_reference' => $evidence->row_reference,
            'page_number' => $evidence->page_number,
            'field_key' => $evidence->field_key?->value,
            'field_label' => $evidence->field_key?->label(),
            'note' => $evidence->note,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function relations(Transaction $transaction): array
    {
        $items = $transaction->outgoingRelations
            ->map(fn ($relation): array => $this->relation($relation, $relation->toTransaction, $transaction))
            ->concat($transaction->incomingRelations->map(
                fn ($relation): array => $this->relation($relation, $relation->fromTransaction, $transaction)
            ));

        return $items->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function relation(TransactionRelation $relation, ?Transaction $other, Transaction $current): array
    {
        return [
            'type' => $relation->type->value,
            'type_label' => $relation->type->label(),
            'confidence' => $relation->confidence,
            'reasons' => $relation->reasons ?? [],
            'other' => $other instanceof Transaction && $other->getKey() !== $current->getKey() ? [
                'id' => $other->getKey(),
                'reference' => $other->reference,
                'description' => $other->description,
                'amount' => $other->amount,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function journal(Transaction $transaction): ?array
    {
        $entry = $transaction->journalEntries->first();

        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry->getKey(),
            'entry_number' => $entry->entry_number,
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'total_debit' => $entry->total_debit,
            'total_credit' => $entry->total_credit,
            'lines' => $entry->lines->map(static fn ($line): array => [
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'account_type' => $line->account?->account_type?->value,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
            ])->all(),
        ];
    }
}

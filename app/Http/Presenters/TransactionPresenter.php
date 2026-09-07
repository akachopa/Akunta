<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionEvidence;
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
}

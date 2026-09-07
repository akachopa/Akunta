<?php

declare(strict_types=1);

namespace App\Services\TransactionIntelligence;

use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Services\Accounting\JournalProposalService;
use App\Services\Ai\ConfidenceEngine;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentProcessing\DocumentStageRecorder;
use App\Services\EconomicEvents\EconomicEventClassificationService;
use App\Services\EntityResolution\EntityResolutionService;
use App\Services\TransactionMatching\TransactionMatchingService;
use Illuminate\Support\Facades\DB;

/**
 * Tahap MATCHING dokumen (plan.md §13.1): entity, duplicate/related, economic event,
 * dan usulan jurnal.
 *
 * Keempatnya dijalankan dalam satu tahap dokumen karena state machine dokumen hanya
 * memiliki MATCHING setelah NORMALIZING. Masing-masing tetap service tersendiri supaya
 * kesalahan dapat dilokalisasi dan diuji terpisah.
 */
class TransactionIntelligenceService
{
    public function __construct(
        private readonly DocumentStageRecorder $stages,
        private readonly EntityResolutionService $entities,
        private readonly TransactionMatchingService $matching,
        private readonly EconomicEventClassificationService $events,
        private readonly JournalProposalService $journals,
        private readonly ConfidenceEngine $confidence,
        private readonly AuditLogger $audit,
    ) {}

    public function process(Document $document): bool
    {
        $document->refresh();

        if ($document->processing_status !== DocumentStatus::Matching) {
            return false;
        }

        $job = $this->stages->currentOrOpen($document, DocumentProcessingStage::Match);
        $job->markRunning();

        $transactions = Transaction::query()
            ->fromDocument($document)
            ->with(['source.document', 'counterpartyEntity', 'economicEvent'])
            ->get();

        if ($transactions->isEmpty()) {
            $this->finish($document, $job, [], 'Dokumen tidak menghasilkan transaksi yang perlu dicocokkan.');

            return true;
        }

        $summary = DB::transaction(function () use ($document, $transactions): array {
            foreach ($transactions as $transaction) {
                if ($transaction->status === TransactionStatus::Normalized) {
                    $transaction->transitionTo(TransactionStatus::Matching);
                }

                $this->entities->resolve($transaction);
                $transaction->refresh();
            }

            $match = $this->matching->match(
                Transaction::query()->fromDocument($document)->with(['source.document'])->get()
            );
            $duplicateIds = $match['duplicate_ids'];

            $classified = 0;
            $journals = 0;
            $needReview = 0;

            foreach (Transaction::query()->fromDocument($document)->with(['source.document', 'economicEvent'])->get() as $transaction) {
                if (in_array($transaction->getKey(), $duplicateIds, true)) {
                    $this->markDuplicate($transaction);
                    $needReview++;

                    continue;
                }

                if (! in_array($transaction->status, [TransactionStatus::Matching, TransactionStatus::Normalized, TransactionStatus::Classified], true)
                    && $transaction->status !== TransactionStatus::NeedReview) {
                    continue;
                }

                $event = $this->events->classify($transaction);
                $transaction->refresh();

                if ($transaction->status === TransactionStatus::Matching) {
                    $transaction->transitionTo(TransactionStatus::Classified);
                } elseif ($transaction->status === TransactionStatus::Normalized) {
                    $transaction->transitionTo(TransactionStatus::Classified);
                } elseif ($transaction->status === TransactionStatus::NeedReview) {
                    $transaction->transitionTo(TransactionStatus::Classified);
                }

                $classified++;

                $proposal = $this->journals->propose($transaction->refresh()->load(['economicEvent', 'source.document']));

                if ($proposal['journal_id'] !== null) {
                    $journals++;
                }

                $assessment = $this->confidence->evaluate([
                    'document_type_confidence' => $document->classification_confidence,
                    'extraction_confidence' => $document->extraction_confidence,
                    'economic_event_confidence' => $event['confidence'],
                    'account_mapping_confidence' => $proposal['confidence'],
                    'entity_match_confidence' => null,
                ], $document->business);

                $transaction->forceFill([
                    'overall_confidence' => $assessment->score,
                ])->save();

                $missing = $event['missing'];
                $needsHuman = ! $assessment->band->allowsAutomaticProcessing()
                    || $missing !== []
                    || $proposal['journal_id'] === null;

                if ($needsHuman) {
                    $reason = $proposal['reason']
                        ?? ($missing[0] ?? 'Klasifikasi transaksi ini perlu diperiksa sebelum dijurnal.');

                    $transaction->transitionTo(TransactionStatus::NeedReview, [
                        'review_reason' => mb_strimwidth($reason, 0, 1000, '…'),
                    ]);
                    $needReview++;
                } else {
                    $transaction->transitionTo(TransactionStatus::Ready, ['review_reason' => null]);
                }
            }

            $document->transitionTo(DocumentStatus::Ready, ['review_reason' => null]);

            return [
                'transaction_count' => $transactions->count(),
                'classified' => $classified,
                'journals' => $journals,
                'duplicates' => $match['duplicates'],
                'related' => $match['related'],
                'need_review' => $needReview,
            ];
        });

        $job->markSucceeded($summary);

        $this->audit->log(
            action: 'document.matched',
            entity: $document,
            before: null,
            after: $summary,
            business: $document->business,
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(Document $document, mixed $job, array $result, string $reason): void
    {
        $job->markSkipped($reason);
        $document->transitionTo(DocumentStatus::Ready, ['review_reason' => null] + $result);
    }

    private function markDuplicate(Transaction $transaction): void
    {
        $related = $transaction->outgoingRelations()->first()
            ?? $transaction->incomingRelations()->first();

        $other = $related?->from_transaction_id === $transaction->getKey()
            ? $related?->toTransaction
            : $related?->fromTransaction;

        $reason = $other instanceof Transaction
            ? sprintf('Transaksi ini tampak duplikat dari %s, sehingga tidak dijurnal ulang.', $other->reference)
            : 'Transaksi ini tampak duplikat dari transaksi lain.';

        if ($transaction->status === TransactionStatus::Matching) {
            $transaction->transitionTo(TransactionStatus::NeedReview, ['review_reason' => $reason]);

            return;
        }

        if ($transaction->status->canTransitionTo(TransactionStatus::NeedReview)) {
            $transaction->transitionTo(TransactionStatus::NeedReview, ['review_reason' => $reason]);
        }
    }
}

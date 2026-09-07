<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Exceptions\ReviewNotAllowed;
use App\Domain\Review\Models\ReviewComment;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionTag;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan manusia atas transaksi di Review Center (plan.md §16, §29.4, §37 Phase 10).
 *
 * Approve tidak memposting jurnal. Posting tetap menunggu akuntan (plan.md §15.2).
 * Transaksi yang ditolak tidak dihapus (plan.md §31, §44.7).
 */
class TransactionReviewService
{
    public function __construct(
        private readonly ReviewTaskService $tasks,
        private readonly JournalPostingService $journals,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(Transaction $transaction, User $actor, ?string $reason = null): ReviewTask
    {
        $this->assertReviewable($transaction, 'disetujui');

        return DB::transaction(function () use ($transaction, $actor, $reason): ReviewTask {
            $before = $this->snapshot($transaction);

            if (in_array($transaction->status, [TransactionStatus::NeedReview, TransactionStatus::Classified], true)) {
                $transaction->transitionTo(TransactionStatus::Ready, ['review_reason' => null]);
            }

            if ($transaction->status === TransactionStatus::Ready) {
                $transaction->transitionTo(TransactionStatus::Approved, ['review_reason' => null]);
            }

            $journal = $this->draftJournal($transaction);

            if ($journal instanceof JournalEntry
                && in_array($journal->status, [JournalEntryStatus::Draft, JournalEntryStatus::PendingApproval], true)
                && $journal->status->canTransitionTo(JournalEntryStatus::Approved)) {
                $journal = $this->journals->approve($journal, $actor);
            }

            if ($journal instanceof JournalEntry && ! $journal->status->isImmutable()) {
                $task = $this->tasks->markAwaitingPost($transaction);
            } else {
                $task = $this->tasks->openOrRefresh(
                    $transaction,
                    $this->tasks->kindFor($transaction),
                    null,
                );
                $this->tasks->complete($task, $actor);
            }

            $this->tasks->recordAction(
                $task,
                ReviewActionType::Approve,
                $actor,
                $reason,
                $before,
                $this->snapshot($transaction->refresh()),
            );

            $this->audit->log(
                action: 'transaction.approved',
                entity: $transaction,
                before: $before,
                after: $this->snapshot($transaction),
                reason: $reason,
                business: $transaction->business,
                actor: $actor,
            );

            return $task;
        });
    }

    public function reject(Transaction $transaction, User $actor, string $reason): ReviewTask
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ReviewNotAllowed::reasonRequired();
        }

        $this->assertRejectable($transaction);

        return DB::transaction(function () use ($transaction, $actor, $reason): ReviewTask {
            $before = $this->snapshot($transaction);

            $journal = $this->draftJournal($transaction);

            if ($journal instanceof JournalEntry && $journal->status->isEditable()) {
                $journal->delete();
            }

            $transaction->transitionTo(TransactionStatus::Rejected, [
                'review_reason' => mb_strimwidth($reason, 0, 1000, '…'),
            ]);

            $task = $this->tasks->openOrRefresh(
                $transaction,
                $this->tasks->kindFor($transaction->refresh()),
                $reason,
            );
            $this->tasks->complete($task, $actor);

            $this->tasks->recordAction(
                $task,
                ReviewActionType::Reject,
                $actor,
                $reason,
                $before,
                $this->snapshot($transaction),
            );

            $this->audit->log(
                action: 'transaction.rejected',
                entity: $transaction,
                before: $before,
                after: $this->snapshot($transaction),
                reason: $reason,
                business: $transaction->business,
                actor: $actor,
            );

            return $task;
        });
    }

    public function post(Transaction $transaction, User $actor, ?string $reason = null): ReviewTask
    {
        if ($transaction->status === TransactionStatus::Posted) {
            throw ReviewNotAllowed::forTransaction($transaction, 'diposting ulang');
        }

        if (in_array($transaction->status, [
            TransactionStatus::NeedReview,
            TransactionStatus::Classified,
            TransactionStatus::Ready,
        ], true)) {
            $this->approve($transaction, $actor, $reason);
            $transaction->refresh();
        }

        if ($transaction->status !== TransactionStatus::Approved) {
            throw ReviewNotAllowed::forTransaction($transaction, 'diposting');
        }

        $journal = $this->postableJournal($transaction);

        if (! $journal instanceof JournalEntry) {
            throw ReviewNotAllowed::missingJournal($transaction);
        }

        return DB::transaction(function () use ($transaction, $journal, $actor, $reason): ReviewTask {
            $before = $this->snapshot($transaction);

            $posted = $this->journals->post($journal, $actor);

            $transaction->transitionTo(TransactionStatus::Posted, [
                'posting_date' => $posted->entry_date->toDateString(),
                'review_reason' => null,
            ]);

            $task = $this->tasks->openOrRefresh(
                $transaction,
                $this->tasks->kindFor($transaction),
                null,
            );
            $this->tasks->complete($task, $actor);

            $this->tasks->recordAction(
                $task,
                ReviewActionType::Post,
                $actor,
                $reason,
                $before,
                $this->snapshot($transaction->refresh()),
            );

            $this->audit->log(
                action: 'transaction.posted',
                entity: $transaction,
                before: $before,
                after: $this->snapshot($transaction),
                reason: $reason,
                business: $transaction->business,
                actor: $actor,
            );

            return $task;
        });
    }

    public function comment(ReviewTask $task, User $actor, string $body): ReviewComment
    {
        $comment = ReviewComment::query()->create([
            'business_id' => $task->business_id,
            'review_task_id' => $task->getKey(),
            'author_id' => $actor->getKey(),
            'body' => $body,
            'created_at' => Carbon::now(),
        ]);

        $this->tasks->recordAction($task, ReviewActionType::Comment, $actor, $body);

        return $comment;
    }

    public function tag(Transaction $transaction, User $actor, string $tag): TransactionTag
    {
        $normalized = mb_strtolower(trim($tag));

        $record = TransactionTag::query()->firstOrCreate(
            [
                'transaction_id' => $transaction->getKey(),
                'tag' => $normalized,
            ],
            [
                'business_id' => $transaction->business_id,
                'created_by' => $actor->getKey(),
                'created_at' => Carbon::now(),
            ],
        );

        $task = ReviewTask::query()
            ->where('transaction_id', $transaction->getKey())
            ->latest('opened_at')
            ->first();

        if ($task instanceof ReviewTask) {
            $this->tasks->recordAction($task, ReviewActionType::Tag, $actor, $normalized);
        }

        return $record;
    }

    private function assertReviewable(Transaction $transaction, string $action): void
    {
        if (! in_array($transaction->status, [
            TransactionStatus::NeedReview,
            TransactionStatus::Ready,
            TransactionStatus::Classified,
        ], true)) {
            throw ReviewNotAllowed::forTransaction($transaction, $action);
        }
    }

    private function assertRejectable(Transaction $transaction): void
    {
        if ($transaction->status === TransactionStatus::Posted
            || ! $transaction->status->canTransitionTo(TransactionStatus::Rejected)) {
            throw ReviewNotAllowed::forTransaction($transaction, 'ditolak');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Transaction $transaction): array
    {
        return [
            'status' => $transaction->status->value,
            'economic_event_id' => $transaction->economic_event_id,
            'review_reason' => $transaction->review_reason,
        ];
    }

    private function draftJournal(Transaction $transaction): ?JournalEntry
    {
        return $transaction->journalEntries()
            ->whereIn('status', [
                JournalEntryStatus::Draft->value,
                JournalEntryStatus::PendingApproval->value,
                JournalEntryStatus::Approved->value,
            ])
            ->latest('created_at')
            ->first();
    }

    private function postableJournal(Transaction $transaction): ?JournalEntry
    {
        return $transaction->journalEntries()
            ->whereIn('status', [
                JournalEntryStatus::Draft->value,
                JournalEntryStatus::PendingApproval->value,
                JournalEntryStatus::Approved->value,
            ])
            ->latest('created_at')
            ->first();
    }
}

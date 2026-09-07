<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Enums\ReviewSubjectType;
use App\Domain\Review\Enums\ReviewTaskKind;
use App\Domain\Review\Enums\ReviewTaskStatus;
use App\Domain\Review\Models\ReviewAction;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Enums\TransactionRelationType;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Membuka, memperbarui, dan menutup tugas Review Center (plan.md §16.2, §23).
 */
class ReviewTaskService
{
    public function ensureDocumentTasks(Business $business): void
    {
        Document::query()
            ->forBusiness($business)
            ->where('processing_status', DocumentStatus::NeedReview->value)
            ->each(fn (Document $document): ?ReviewTask => $this->syncFromDocument($document));

        ReviewTask::query()
            ->forBusiness($business)
            ->where('subject_type', ReviewSubjectType::Document->value)
            ->whereIn('status', [ReviewTaskStatus::Open->value, ReviewTaskStatus::AwaitingPost->value])
            ->with('document')
            ->get()
            ->each(function (ReviewTask $task): void {
                if ($task->document instanceof Document) {
                    $this->syncFromDocument($task->document);
                }
            });
    }

    public function syncFromTransaction(Transaction $transaction): ?ReviewTask
    {
        $open = $this->openTaskForTransaction($transaction);

        return match ($transaction->status) {
            TransactionStatus::NeedReview, TransactionStatus::Ready => $this->openOrRefresh(
                $transaction,
                $this->kindFor($transaction),
                $transaction->review_reason,
            ),
            TransactionStatus::Approved => $this->markAwaitingPost($transaction, $open),
            TransactionStatus::Posted, TransactionStatus::Rejected => $this->complete(
                $open,
                null,
                $transaction->status === TransactionStatus::Rejected
                    ? ReviewTaskStatus::Completed
                    : ReviewTaskStatus::Completed,
            ),
            default => $open,
        };
    }

    public function syncFromDocument(Document $document): ?ReviewTask
    {
        $open = ReviewTask::query()
            ->where('document_id', $document->getKey())
            ->whereIn('status', [ReviewTaskStatus::Open->value, ReviewTaskStatus::AwaitingPost->value])
            ->first();

        if ($document->processing_status === DocumentStatus::NeedReview) {
            if ($open instanceof ReviewTask) {
                $open->forceFill([
                    'kind' => ReviewTaskKind::NeedInformation,
                    'reason' => $document->review_reason,
                ])->save();

                return $open;
            }

            return ReviewTask::query()->create([
                'business_id' => $document->business_id,
                'subject_type' => ReviewSubjectType::Document,
                'document_id' => $document->getKey(),
                'kind' => ReviewTaskKind::NeedInformation,
                'status' => ReviewTaskStatus::Open,
                'reason' => $document->review_reason,
                'opened_at' => Carbon::now(),
            ]);
        }

        if ($open instanceof ReviewTask && in_array($document->processing_status, [
            DocumentStatus::Ready,
            DocumentStatus::Archived,
            DocumentStatus::Failed,
            DocumentStatus::Unsupported,
        ], true)) {
            return $this->complete($open, null, ReviewTaskStatus::Cancelled);
        }

        return $open;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordAction(
        ReviewTask $task,
        ReviewActionType $action,
        User $actor,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
    ): ReviewAction {
        return ReviewAction::query()->create([
            'business_id' => $task->business_id,
            'review_task_id' => $task->getKey(),
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'created_at' => Carbon::now(),
        ]);
    }

    public function complete(?ReviewTask $task, ?User $actor, ReviewTaskStatus $status = ReviewTaskStatus::Completed): ?ReviewTask
    {
        if (! $task instanceof ReviewTask || ! $task->status->isOpen()) {
            return $task;
        }

        $task->forceFill([
            'status' => $status,
            'completed_by' => $actor?->getKey(),
            'completed_at' => Carbon::now(),
        ])->save();

        return $task;
    }

    public function markAwaitingPost(Transaction $transaction, ?ReviewTask $task = null): ReviewTask
    {
        $task ??= $this->openOrRefresh(
            $transaction,
            ReviewTaskKind::ReadyForApproval,
            $transaction->review_reason,
        );

        $task->forceFill([
            'status' => ReviewTaskStatus::AwaitingPost,
            'kind' => ReviewTaskKind::ReadyForApproval,
        ])->save();

        return $task;
    }

    public function openOrRefresh(Transaction $transaction, ReviewTaskKind $kind, ?string $reason): ReviewTask
    {
        $existing = $this->openTaskForTransaction($transaction);

        if ($existing instanceof ReviewTask) {
            $existing->forceFill([
                'kind' => $kind,
                'reason' => $reason,
                'status' => ReviewTaskStatus::Open,
                'completed_by' => null,
                'completed_at' => null,
            ])->save();

            return $existing;
        }

        return ReviewTask::query()->create([
            'business_id' => $transaction->business_id,
            'subject_type' => ReviewSubjectType::Transaction,
            'transaction_id' => $transaction->getKey(),
            'kind' => $kind,
            'status' => ReviewTaskStatus::Open,
            'reason' => $reason,
            'opened_at' => Carbon::now(),
        ]);
    }

    public function kindFor(Transaction $transaction): ReviewTaskKind
    {
        if ($transaction->status === TransactionStatus::NeedReview && $this->isDuplicate($transaction)) {
            return ReviewTaskKind::Duplicate;
        }

        if ($transaction->status === TransactionStatus::NeedReview) {
            return ReviewTaskKind::NeedInformation;
        }

        return ReviewTaskKind::ReadyForApproval;
    }

    public function isDuplicate(Transaction $transaction): bool
    {
        return $transaction->outgoingRelations()
            ->where('type', TransactionRelationType::Duplicate->value)
            ->exists()
            || $transaction->incomingRelations()
                ->where('type', TransactionRelationType::Duplicate->value)
                ->exists();
    }

    private function openTaskForTransaction(Transaction $transaction): ?ReviewTask
    {
        return ReviewTask::query()
            ->where('transaction_id', $transaction->getKey())
            ->whereIn('status', [ReviewTaskStatus::Open->value, ReviewTaskStatus::AwaitingPost->value])
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\Documents\Models\Document;
use App\Domain\Review\Models\ReviewAction;
use App\Domain\Review\Models\ReviewComment;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionTag;
use App\Models\User;

/**
 * Payload Review Center untuk Inertia dan API v1 (plan.md §16.2, §29.8).
 */
class ReviewPresenter
{
    public function __construct(private readonly TransactionPresenter $transactions) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(ReviewTask $task): array
    {
        return [
            'id' => $task->getKey(),
            'subject_type' => $task->subject_type->value,
            'subject_type_label' => $task->subject_type->label(),
            'kind' => $task->kind->value,
            'kind_label' => $task->kind->label(),
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'reason' => $task->reason,
            'opened_at' => $task->opened_at->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'transaction' => $task->transaction instanceof Transaction
                ? $this->transactions->summary($task->transaction)
                : null,
            'document' => $task->document instanceof Document ? [
                'id' => $task->document->getKey(),
                'reference' => $task->document->reference,
                'original_filename' => $task->document->original_filename,
                'processing_status' => $task->document->processing_status->value,
                'processing_status_label' => $task->document->processing_status->label(),
                'review_reason' => $task->document->review_reason,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(ReviewTask $task): array
    {
        $task->loadMissing([
            'transaction.source.document',
            'transaction.evidence',
            'transaction.counterpartyEntity',
            'transaction.economicEvent',
            'transaction.outgoingRelations.toTransaction',
            'transaction.incomingRelations.fromTransaction',
            'transaction.journalEntries.lines.account',
            'transaction.tags',
            'document',
            'actions.actor',
            'comments.author',
        ]);

        return $this->summary($task) + [
            'transaction_detail' => $task->transaction instanceof Transaction
                ? $this->transactions->detail($task->transaction) + [
                    'tags' => $task->transaction->tags
                        ->map(static fn (TransactionTag $tag): string => $tag->tag)
                        ->all(),
                ]
                : null,
            'actions' => $task->actions->map(fn (ReviewAction $action): array => [
                'id' => $action->getKey(),
                'action' => $action->action->value,
                'action_label' => $action->action->label(),
                'reason' => $action->reason,
                'actor_name' => $action->actor instanceof User ? $action->actor->name : null,
                'created_at' => $action->created_at->toIso8601String(),
            ])->all(),
            'comments' => $task->comments->map(fn (ReviewComment $comment): array => [
                'id' => $comment->getKey(),
                'body' => $comment->body,
                'author_name' => $comment->author instanceof User ? $comment->author->name : null,
                'created_at' => $comment->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}

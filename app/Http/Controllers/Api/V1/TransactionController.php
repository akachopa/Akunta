<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Enums\TransactionFilter;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Http\Presenters\TransactionPresenter;
use App\Models\User;
use App\Services\Accounting\JournalProposalService;
use App\Services\EconomicEvents\EconomicEventClassificationService;
use App\Services\Review\ReviewTaskService;
use App\Services\Review\TransactionReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * plan.md §29.4:
 *
 * GET  /businesses/{id}/transactions
 * GET  /transactions/{id}
 * POST /transactions/{id}/approve
 * POST /transactions/{id}/reject
 * POST /transactions/{id}/post
 * POST /transactions/{id}/classify
 *
 * Posting jurnal tetap endpoint terpisah: approve ≠ post (plan.md §15.2).
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionPresenter $presenter,
        private readonly TransactionReviewService $review,
        private readonly ReviewTaskService $tasks,
        private readonly EconomicEventClassificationService $events,
        private readonly JournalProposalService $journals,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewAny', [Transaction::class, $business]);

        $filter = TransactionFilter::tryFrom((string) $request->query('filter')) ?? TransactionFilter::All;

        $query = $filter->apply(Transaction::query()->forBusiness($business));

        // Penelusuran balik dari dokumen ke transaksinya (plan.md §45.12).
        $documentId = $request->string('document')->value();

        if ($documentId !== '') {
            $document = Document::query()->forBusiness($business)->find($documentId);

            $query = $document instanceof Document
                ? $query->fromDocument($document)
                : $query->whereRaw('1 = 0');
        }

        // plan.md §40: list yang dipaginasi dan filter di sisi server.
        $transactions = $query
            ->with(['source.document', 'counterpartyEntity', 'economicEvent'])
            ->orderByDesc('transaction_date')
            ->orderBy('reference')
            ->paginate(min((int) $request->integer('per_page', 50) ?: 50, 200));

        return response()->json([
            'data' => collect($transactions->items())
                ->map(fn (Transaction $transaction): array => $this->presenter->summary($transaction) + [
                    'source' => $transaction->source === null ? null : $this->presenter->source($transaction->source),
                ])
                ->all(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);

        $transaction->load([
            'source.document',
            'evidence',
            'counterpartyEntity',
            'economicEvent',
            'outgoingRelations.toTransaction',
            'incomingRelations.fromTransaction',
            'journalEntries.lines.account',
            'tags',
        ]);

        return response()->json(['data' => $this->presenter->detail($transaction)]);
    }

    public function approve(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('approve', $transaction);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $task = $this->review->approve($transaction, $this->actor($request), $validated['reason'] ?? null);

        return response()->json(['data' => $this->payload($transaction->refresh(), $task)]);
    }

    public function reject(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('reject', $transaction);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $task = $this->review->reject($transaction, $this->actor($request), $validated['reason']);

        return response()->json(['data' => $this->payload($transaction->refresh(), $task)]);
    }

    public function post(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('approve', $transaction);
        abort_unless(
            $this->actor($request)->hasBusinessPermission($transaction->business_id, PermissionSlug::JournalPost),
            403
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $task = $this->review->post($transaction, $this->actor($request), $validated['reason'] ?? null);

        return response()->json(['data' => $this->payload($transaction->refresh(), $task)]);
    }

    public function classify(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('review', $transaction);

        $validated = $request->validate([
            'event_code' => ['required', 'string', Rule::in(EconomicEventCode::values())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $this->actor($request);

        $this->events->correct(
            $transaction,
            EconomicEventCode::from($validated['event_code']),
            $actor,
            $validated['reason'] ?? null,
        );

        $this->journals->propose($transaction->refresh());

        if ($transaction->status->canTransitionTo(TransactionStatus::Ready)) {
            $transaction->transitionTo(TransactionStatus::Ready, ['review_reason' => null]);
        }

        $task = $this->tasks->syncFromTransaction($transaction->refresh());

        if ($task instanceof ReviewTask) {
            $this->tasks->recordAction(
                $task,
                ReviewActionType::Correct,
                $actor,
                $validated['reason'] ?? null,
            );
        }

        return response()->json(['data' => $this->payload($transaction->refresh(), $task)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Transaction $transaction, ?ReviewTask $task): array
    {
        $transaction->load([
            'source.document',
            'evidence',
            'counterpartyEntity',
            'economicEvent',
            'outgoingRelations.toTransaction',
            'incomingRelations.fromTransaction',
            'journalEntries.lines.account',
            'tags',
        ]);

        return $this->presenter->detail($transaction) + [
            'review_task_id' => $task?->getKey(),
            'review_task_status' => $task?->status->value,
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Enums\ReviewQueueFilter;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Presenters\ReviewPresenter;
use App\Models\User;
use App\Services\Accounting\JournalProposalService;
use App\Services\EconomicEvents\EconomicEventClassificationService;
use App\Services\Review\ReviewTaskService;
use App\Services\Review\TransactionReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review Center (plan.md §6, §16, §37 Phase 10).
 *
 * Antrean kerja exception-based: yang tampil adalah transaksi yang perlu manusia.
 * Posting jurnal tetap menunggu akuntan (plan.md §15.2).
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewPresenter $presenter,
        private readonly ReviewTaskService $tasks,
        private readonly TransactionReviewService $review,
        private readonly EconomicEventClassificationService $events,
        private readonly JournalProposalService $journals,
    ) {}

    public function index(Request $request, Business $business): Response
    {
        $this->authorize('viewAny', [ReviewTask::class, $business]);

        $this->tasks->ensureDocumentTasks($business);

        $filter = ReviewQueueFilter::tryFrom((string) $request->query('filter'))
            ?? ReviewQueueFilter::NeedInformation;

        $query = $filter->apply(ReviewTask::query()->forBusiness($business));

        $tasks = $query
            ->with(['transaction.counterpartyEntity', 'transaction.economicEvent', 'document'])
            ->orderByDesc('opened_at')
            ->paginate(50)
            ->withQueryString();

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('review/Index', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'filter' => $filter->value,
            'filters' => array_map(
                static fn (ReviewQueueFilter $case): array => ['value' => $case->value, 'label' => $case->label()],
                ReviewQueueFilter::cases()
            ),
            'counts' => $this->counts($business),
            'tasks' => [
                'data' => collect($tasks->items())
                    ->map(fn (ReviewTask $task): array => $this->presenter->summary($task))
                    ->all(),
                'meta' => [
                    'current_page' => $tasks->currentPage(),
                    'last_page' => $tasks->lastPage(),
                    'per_page' => $tasks->perPage(),
                    'total' => $tasks->total(),
                ],
            ],
            'can' => [
                'approve' => $user->hasBusinessPermission($business, PermissionSlug::TransactionApprove),
            ],
        ]);
    }

    public function show(Request $request, Business $business, ReviewTask $review): Response
    {
        $this->guardTask($business, $review);
        $this->authorize('view', $review);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $transaction = $review->transaction;

        return Inertia::render('review/Show', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'task' => $this->presenter->detail($review),
            'can' => [
                'review' => $user->can('review', $review),
                'approve' => $transaction instanceof Transaction && $user->can('approve', $transaction),
                'post' => $transaction instanceof Transaction
                    && $user->hasBusinessPermission($business, PermissionSlug::JournalPost),
            ],
        ]);
    }

    public function approve(Request $request, Business $business, ReviewTask $review): RedirectResponse
    {
        $this->guardTask($business, $review);
        $transaction = $this->requireTransaction($review);
        $this->authorize('approve', $transaction);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->review->approve($transaction, $this->actor($request), $validated['reason'] ?? null);

        return back()->with('success', sprintf('Transaksi %s disetujui. Posting tetap menunggu akuntan.', $transaction->reference));
    }

    public function reject(Request $request, Business $business, ReviewTask $review): RedirectResponse
    {
        $this->guardTask($business, $review);
        $transaction = $this->requireTransaction($review);
        $this->authorize('reject', $transaction);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->review->reject($transaction, $this->actor($request), $validated['reason']);

        return back()->with('success', sprintf('Transaksi %s ditolak. Jejaknya tetap tersimpan.', $transaction->reference));
    }

    public function post(Request $request, Business $business, ReviewTask $review): RedirectResponse
    {
        $this->guardTask($business, $review);
        $transaction = $this->requireTransaction($review);
        $this->authorize('approve', $transaction);
        abort_unless(
            $this->actor($request)->hasBusinessPermission($business, PermissionSlug::JournalPost),
            403
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->review->post($transaction, $this->actor($request), $validated['reason'] ?? null);

        return back()->with('success', sprintf('Transaksi %s diposting ke ledger.', $transaction->reference));
    }

    public function comment(Request $request, Business $business, ReviewTask $review): RedirectResponse
    {
        $this->guardTask($business, $review);
        $this->authorize('review', $review);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $this->review->comment($review, $this->actor($request), $validated['body']);

        return back()->with('success', 'Komentar tersimpan.');
    }

    public function tag(Request $request, Business $business, ReviewTask $review): RedirectResponse
    {
        $this->guardTask($business, $review);
        $transaction = $this->requireTransaction($review);
        $this->authorize('review', $transaction);

        $validated = $request->validate([
            'tag' => ['required', 'string', 'max:64'],
        ]);

        $this->review->tag($transaction, $this->actor($request), $validated['tag']);

        return back()->with('success', 'Tag ditambahkan.');
    }

    public function classify(Request $request, Business $business, ReviewTask $review): RedirectResponse
    {
        $this->guardTask($business, $review);
        $transaction = $this->requireTransaction($review);
        $this->authorize('review', $transaction);

        $validated = $request->validate([
            'event_code' => ['required', 'string', Rule::in(EconomicEventCode::values())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $this->actor($request);
        $before = ['economic_event' => $transaction->economicEvent?->code];

        $this->events->correct(
            $transaction,
            EconomicEventCode::from($validated['event_code']),
            $actor,
            $validated['reason'] ?? null,
        );

        $this->journals->propose($transaction->refresh());
        $task = $this->tasks->syncFromTransaction($transaction->refresh()) ?? $review;

        $this->tasks->recordAction(
            $task,
            ReviewActionType::Correct,
            $actor,
            $validated['reason'] ?? null,
            $before,
            ['economic_event' => $transaction->economicEvent?->code],
        );

        return back()->with('success', 'Klasifikasi peristiwa ekonomi disimpan.');
    }

    /**
     * @return array<string, int>
     */
    private function counts(Business $business): array
    {
        $counts = [];

        foreach (ReviewQueueFilter::cases() as $case) {
            $counts[$case->value] = $case->apply(ReviewTask::query()->forBusiness($business))->count();
        }

        return $counts;
    }

    private function requireTransaction(ReviewTask $task): Transaction
    {
        $task->loadMissing('transaction');

        abort_unless($task->transaction instanceof Transaction, 404);

        return $task->transaction;
    }

    private function guardTask(Business $business, ReviewTask $review): ReviewTask
    {
        abort_unless($review->business_id === $business->getKey(), 404);

        return $review;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}

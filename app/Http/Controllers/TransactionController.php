<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Domain\Review\Enums\ReviewActionType;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Enums\TransactionFilter;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Presenters\TransactionPresenter;
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
 * Daftar transaksi canonical hasil normalisasi (plan.md §8.2, §37 Phase 5–9).
 *
 * Halaman ini menampilkan perpindahan nilai dari dokumen beserta pihak lawan, peristiwa
 * ekonomi, dan usulan jurnal draft. Posting tetap menunggu akuntan (plan.md §15.2).
 */
class TransactionController extends Controller
{
    public function __construct(private readonly TransactionPresenter $presenter) {}

    public function index(Request $request, Business $business): Response
    {
        $this->authorize('viewAny', [Transaction::class, $business]);

        $filter = TransactionFilter::tryFrom((string) $request->query('filter')) ?? TransactionFilter::All;

        $query = $filter->apply(Transaction::query()->forBusiness($business));

        /*
         * Penyaringan per dokumen adalah jalan masuk dari halaman dokumen: user menekan
         * "lihat transaksi" pada satu rekening koran dan ingin melihat justru transaksi
         * yang dihasilkannya, bukan seluruh transaksi bisnis (plan.md §45.12).
         */
        $documentId = $request->string('document')->value();
        $document = null;

        if ($documentId !== '') {
            $document = Document::query()->forBusiness($business)->find($documentId);

            $query = $document instanceof Document
                ? $query->fromDocument($document)
                : $query->whereRaw('1 = 0');
        }

        $transactions = $query
            ->with(['source.document', 'counterpartyEntity', 'economicEvent'])
            ->orderByDesc('transaction_date')
            ->orderBy('reference')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('transactions/Index', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'filter' => $filter->value,
            'filters' => array_map(
                static fn (TransactionFilter $case): array => ['value' => $case->value, 'label' => $case->label()],
                TransactionFilter::cases()
            ),
            'counts' => $this->counts($business),
            'document' => $document instanceof Document ? [
                'id' => $document->getKey(),
                'reference' => $document->reference,
                'original_filename' => $document->original_filename,
            ] : null,
            'transactions' => [
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
            ],
        ]);
    }

    public function show(Request $request, Business $business, Transaction $transaction): Response
    {
        $this->authorize('view', $transaction);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

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

        return Inertia::render('transactions/Show', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'transaction' => $this->presenter->detail($transaction),
            'can' => [
                'classify' => $user->can('review', $transaction),
                'approve' => $user->can('approve', $transaction),
                'post' => $user->hasBusinessPermission($business, PermissionSlug::JournalPost),
            ],
        ]);
    }

    public function classify(Request $request, Business $business, Transaction $transaction): RedirectResponse
    {
        $this->authorize('review', $transaction);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'event_code' => ['required', 'string', Rule::in(EconomicEventCode::values())],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $event = EconomicEventCode::from($validated['event_code']);

        app(EconomicEventClassificationService::class)->correct(
            $transaction,
            $event,
            $user,
            $validated['reason'] ?? null,
        );

        app(JournalProposalService::class)->propose($transaction->refresh());

        if ($transaction->status->canTransitionTo(TransactionStatus::Ready)) {
            $transaction->transitionTo(TransactionStatus::Ready, ['review_reason' => null]);
        }

        $tasks = app(ReviewTaskService::class);
        $task = $tasks->syncFromTransaction($transaction->refresh());

        if ($task instanceof ReviewTask) {
            $tasks->recordAction(
                $task,
                ReviewActionType::Correct,
                $user,
                $validated['reason'] ?? null,
            );
        }

        return back()->with('success', 'Klasifikasi peristiwa ekonomi disimpan.');
    }

    public function approve(Request $request, Business $business, Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        app(TransactionReviewService::class)->approve(
            $transaction,
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        return back()->with('success', sprintf('Transaksi %s disetujui. Posting tetap menunggu akuntan.', $transaction->reference));
    }

    public function reject(Request $request, Business $business, Transaction $transaction): RedirectResponse
    {
        $this->authorize('reject', $transaction);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        app(TransactionReviewService::class)->reject(
            $transaction,
            $this->actor($request),
            $validated['reason'],
        );

        return back()->with('success', sprintf('Transaksi %s ditolak. Jejaknya tetap tersimpan.', $transaction->reference));
    }

    public function post(Request $request, Business $business, Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);
        abort_unless(
            $this->actor($request)->hasBusinessPermission($business, PermissionSlug::JournalPost),
            403
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        app(TransactionReviewService::class)->post(
            $transaction,
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        return back()->with('success', sprintf('Transaksi %s diposting ke ledger.', $transaction->reference));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function counts(Business $business): array
    {
        $counts = [];

        foreach (TransactionFilter::cases() as $case) {
            $counts[$case->value] = $case->apply(Transaction::query()->forBusiness($business))->count();
        }

        return $counts;
    }
}

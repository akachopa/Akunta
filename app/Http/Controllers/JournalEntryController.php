<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Business\Models\Business;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryController extends Controller
{
    public function __construct(private readonly JournalPostingService $posting)
    {
    }

    public function index(Request $request, Business $business): Response
    {
        $this->authorize('viewAny', [JournalEntry::class, $business]);

        $entries = JournalEntry::query()
            ->forBusiness($business)
            ->with('accountingPeriod:id,name')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('accounting/Journal', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'entries' => $entries->through(static fn (JournalEntry $entry): array => [
                'id' => $entry->getKey(),
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date->toDateString(),
                'description' => $entry->description,
                'status' => $entry->status->value,
                'period' => $entry->accountingPeriod->name,
                'total_debit' => $entry->total_debit,
                'total_credit' => $entry->total_credit,
            ]),
            'filters' => ['status' => $request->string('status')->toString()],
            'accounts' => ChartOfAccount::query()
                ->forBusiness($business)
                ->postable()
                ->ordered()
                ->get(['id', 'code', 'name'])
                ->map(static fn (ChartOfAccount $account): array => [
                    'id' => $account->getKey(),
                    'label' => $account->code.' — '.$account->name,
                ]),
            'periods' => AccountingPeriod::query()
                ->forBusiness($business)
                ->orderBy('start_date')
                ->get(['id', 'name', 'status'])
                ->map(static fn (AccountingPeriod $period): array => [
                    'id' => $period->getKey(),
                    'name' => $period->name,
                    'status' => $period->status->value,
                ]),
        ]);
    }

    public function store(StoreJournalEntryRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('create', [JournalEntry::class, $business]);

        $data = JournalEntryData::fromArray($request->toDomainPayload());

        $entry = $this->posting->createDraft($business, $data, $request->user());

        if ($request->boolean('post')) {
            $this->authorize('post', $entry);
            $entry = $this->posting->post($entry, $request->user());
        }

        return redirect()
            ->route('businesses.journals.show', [$business, $entry])
            ->with('success', "Journal entry {$entry->entry_number} tersimpan.");
    }

    public function show(Business $business, JournalEntry $journal): Response
    {
        $this->authorize('view', $journal);

        $journal->load(['lines.account:id,code,name', 'accountingPeriod:id,name,status', 'sources']);

        return Inertia::render('accounting/JournalEntry', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'entry' => [
                'id' => $journal->getKey(),
                'entry_number' => $journal->entry_number,
                'entry_date' => $journal->entry_date->toDateString(),
                'description' => $journal->description,
                'status' => $journal->status->value,
                'source_type' => $journal->source_type,
                'period' => $journal->accountingPeriod->name,
                'period_status' => $journal->accountingPeriod->status->value,
                'total_debit' => $journal->total_debit,
                'total_credit' => $journal->total_credit,
                'is_balanced' => $journal->isBalanced(),
                'reversal_of_id' => $journal->reversal_of_id,
                'reversed_by_entry_id' => $journal->reversed_by_entry_id,
                'reversal_reason' => $journal->reversal_reason,
                'lines' => $journal->lines->map(static fn (JournalEntryLine $line): array => [
                    'id' => $line->getKey(),
                    'line_number' => $line->line_number,
                    'account_code' => $line->account->code,
                    'account_name' => $line->account->name,
                    'description' => $line->description,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ]),
            ],
        ]);
    }

    public function post(Request $request, Business $business, JournalEntry $journal): RedirectResponse
    {
        $this->authorize('post', $journal);

        $this->posting->post($journal, $request->user());

        return back()->with('success', "Journal entry {$journal->entry_number} diposting ke ledger.");
    }

    public function approve(Request $request, Business $business, JournalEntry $journal): RedirectResponse
    {
        $this->authorize('approve', $journal);

        $this->posting->approve($journal, $request->user());

        return back()->with('success', "Journal entry {$journal->entry_number} disetujui.");
    }

    /**
     * plan.md §44.6: koreksi posted journal hanya melalui reversal.
     */
    public function reverse(Request $request, Business $business, JournalEntry $journal): RedirectResponse
    {
        $this->authorize('reverse', $journal);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'reversal_date' => ['nullable', 'date'],
        ]);

        $reversal = $this->posting->reverse(
            $journal,
            $request->user(),
            $validated['reason'],
            isset($validated['reversal_date'])
                ? Carbon::parse($validated['reversal_date'])->startOfDay()
                : null
        );

        return redirect()
            ->route('businesses.journals.show', [$business, $reversal])
            ->with('success', "Reversal {$reversal->entry_number} dibuat dan diposting.");
    }
}

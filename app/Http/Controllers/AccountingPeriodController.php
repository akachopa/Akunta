<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Business\Models\Business;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingPeriodController extends Controller
{
    public function __construct(private readonly AccountingPeriodService $periods)
    {
    }

    public function index(Business $business): Response
    {
        $this->authorize('viewAny', [AccountingPeriod::class, $business]);

        return Inertia::render('accounting/Periods', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'periods' => AccountingPeriod::query()
                ->forBusiness($business)
                ->withCount('journalEntries')
                ->orderBy('start_date')
                ->get()
                ->map(static fn (AccountingPeriod $period): array => [
                    'id' => $period->getKey(),
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'status' => $period->status->value,
                    'journal_entries_count' => $period->journal_entries_count,
                    'closed_at' => $period->closed_at?->toIso8601String(),
                    'reopened_at' => $period->reopened_at?->toIso8601String(),
                ]),
        ]);
    }

    public function close(Request $request, Business $business, AccountingPeriod $period): RedirectResponse
    {
        $this->authorize('close', $period);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->periods->close($period, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', "Periode {$period->name} ditutup.");
    }

    /**
     * plan.md §20.4: reopen harus dicatat dalam audit log, sehingga alasan wajib diisi.
     */
    public function reopen(Request $request, Business $business, AccountingPeriod $period): RedirectResponse
    {
        $this->authorize('reopen', $period);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->periods->reopen($period, $request->user(), $validated['reason']);

        return back()->with('success', "Periode {$period->name} dibuka kembali.");
    }
}

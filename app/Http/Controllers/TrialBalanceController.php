<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Business\Models\Business;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TrialBalanceController extends Controller
{
    public function __construct(private readonly TrialBalanceService $trialBalance)
    {
    }

    public function __invoke(Request $request, Business $business): Response
    {
        $this->authorize('viewReports', $business);

        $periods = AccountingPeriod::query()
            ->forBusiness($business)
            ->orderBy('start_date')
            ->get();

        $selected = $request->filled('period')
            ? $periods->firstWhere('id', $request->string('period')->toString())
            : $periods->last();

        $report = $selected !== null
            ? $this->trialBalance->forPeriod($business, $selected)
            : $this->trialBalance->forDateRange($business, $business->opening_date->copy(), Carbon::now());

        return Inertia::render('accounting/TrialBalance', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'report' => $report,
            'periods' => $periods->map(static fn (AccountingPeriod $period): array => [
                'id' => $period->getKey(),
                'name' => $period->name,
                'status' => $period->status->value,
            ]),
            'selectedPeriod' => $selected?->getKey(),
        ]);
    }
}

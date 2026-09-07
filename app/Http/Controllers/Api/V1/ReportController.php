<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Business\Models\Business;
use App\Http\Controllers\Controller;
use App\Services\Accounting\TrialBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * plan.md §29.7. Pada Phase 2 hanya trial balance yang tersedia; income statement,
 * balance sheet, cash flow, dan general ledger adalah Phase 12.
 */
class ReportController extends Controller
{
    public function __construct(private readonly TrialBalanceService $trialBalance) {}

    public function trialBalance(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewReports', $business);

        $validated = $request->validate([
            'period_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        if (isset($validated['period_id'])) {
            $period = AccountingPeriod::query()
                ->forBusiness($business)
                ->findOrFail($validated['period_id']);

            return response()->json(['data' => $this->trialBalance->forPeriod($business, $period)]);
        }

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : $business->opening_date->copy();

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->startOfDay()
            : Carbon::now()->startOfDay();

        return response()->json(['data' => $this->trialBalance->forDateRange($business, $from, $to)]);
    }
}

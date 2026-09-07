<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Business\Models\Business;
use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan.md §29.9: GET /businesses/{id}/periods, POST /periods/{id}/close,
 * POST /periods/{id}/reopen.
 *
 * GET /periods/{id}/readiness adalah Phase 13 dan belum tersedia.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(private readonly AccountingPeriodService $periods)
    {
    }

    public function index(Business $business): JsonResponse
    {
        $this->authorize('viewAny', [AccountingPeriod::class, $business]);

        $periods = AccountingPeriod::query()
            ->forBusiness($business)
            ->orderBy('start_date')
            ->get()
            ->map(fn (AccountingPeriod $period): array => $this->payload($period));

        return response()->json(['data' => $periods]);
    }

    public function close(Request $request, AccountingPeriod $period): JsonResponse
    {
        $this->authorize('close', $period);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $period = $this->periods->close($period, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $this->payload($period)]);
    }

    public function reopen(Request $request, AccountingPeriod $period): JsonResponse
    {
        $this->authorize('reopen', $period);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $period = $this->periods->reopen($period, $request->user(), $validated['reason']);

        return response()->json(['data' => $this->payload($period)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AccountingPeriod $period): array
    {
        return [
            'id' => $period->getKey(),
            'business_id' => $period->business_id,
            'name' => $period->name,
            'fiscal_year' => $period->fiscal_year,
            'period_number' => $period->period_number,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'status' => $period->status->value,
            'closed_at' => $period->closed_at?->toIso8601String(),
            'reopened_at' => $period->reopened_at?->toIso8601String(),
            'reopen_reason' => $period->reopen_reason,
        ];
    }
}

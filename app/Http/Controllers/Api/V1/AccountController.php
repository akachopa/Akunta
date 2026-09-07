<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Models\Business;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartOfAccountRequest;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan.md §29.6: GET/POST /businesses/{id}/accounts.
 */
class AccountController extends Controller
{
    public function __construct(private readonly ChartOfAccountsService $service) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorize('viewAny', [ChartOfAccount::class, $business]);

        $accounts = ChartOfAccount::query()
            ->forBusiness($business)
            ->when($request->boolean('postable_only'), fn ($query) => $query->postable())
            ->ordered()
            ->get()
            ->map(fn (ChartOfAccount $account): array => $this->payload($account));

        return response()->json(['data' => $accounts]);
    }

    public function store(StoreChartOfAccountRequest $request, Business $business): JsonResponse
    {
        $this->authorize('create', [ChartOfAccount::class, $business]);

        $account = $this->service->createAccount($business, $request->validated(), $request->user());

        return response()->json(['data' => $this->payload($account)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChartOfAccount $account): array
    {
        return [
            'id' => $account->getKey(),
            'code' => $account->code,
            'name' => $account->name,
            'parent_id' => $account->parent_id,
            'account_type' => $account->account_type->value,
            'normal_balance' => $account->normal_balance->value,
            'account_role' => $account->account_role?->value,
            'reporting_group' => $account->reporting_group->value,
            'is_postable' => $account->is_postable,
            'is_system' => $account->is_system,
            'is_active' => $account->is_active,
        ];
    }
}

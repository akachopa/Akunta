<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Models\Business;
use App\Http\Requests\StoreChartOfAccountRequest;
use App\Http\Requests\UpdateChartOfAccountRequest;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChartOfAccountController extends Controller
{
    public function __construct(private readonly ChartOfAccountsService $service)
    {
    }

    public function index(Business $business): Response
    {
        $this->authorize('viewAny', [ChartOfAccount::class, $business]);

        return Inertia::render('accounting/ChartOfAccounts', [
            'business' => ['id' => $business->getKey(), 'name' => $business->name],
            'accounts' => ChartOfAccount::query()
                ->forBusiness($business)
                ->ordered()
                ->get()
                ->map(static fn (ChartOfAccount $account): array => [
                    'id' => $account->getKey(),
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type->value,
                    'account_type_label' => $account->account_type->label(),
                    'normal_balance' => $account->normal_balance->value,
                    'account_role' => $account->account_role?->value,
                    'reporting_group' => $account->reporting_group->value,
                    'is_postable' => $account->is_postable,
                    'is_system' => $account->is_system,
                    'is_active' => $account->is_active,
                    'parent_id' => $account->parent_id,
                ]),
            'accountTypes' => array_map(
                static fn (AccountType $type): array => ['value' => $type->value, 'label' => $type->label()],
                AccountType::cases()
            ),
            'accountRoles' => AccountRole::values(),
        ]);
    }

    public function store(StoreChartOfAccountRequest $request, Business $business): RedirectResponse
    {
        $this->authorize('create', [ChartOfAccount::class, $business]);

        $this->service->createAccount($business, $request->validated(), $request->user());

        return back()->with('success', 'Akun berhasil ditambahkan.');
    }

    public function update(
        UpdateChartOfAccountRequest $request,
        Business $business,
        ChartOfAccount $account,
    ): RedirectResponse {
        $this->authorize('update', $account);

        $this->service->updateAccount($account, $request->validated(), $request->user());

        return back()->with('success', 'Akun berhasil diperbarui.');
    }

    /**
     * Akun tidak pernah dihapus, hanya dinonaktifkan (plan.md §12.2).
     */
    public function destroy(Request $request, Business $business, ChartOfAccount $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $this->service->deactivateAccount($account, $request->user());

        return back()->with('success', 'Akun dinonaktifkan.');
    }
}

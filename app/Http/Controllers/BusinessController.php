<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounting\Models\CoaTemplate;
use App\Domain\Business\Enums\AccountingBasis;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use App\Http\Requests\StoreBusinessRequest;
use App\Services\Business\BusinessProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessController extends Controller
{
    public function __construct(private readonly BusinessProvisioningService $provisioning)
    {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('businesses/Index', [
            'businesses' => $user->businesses()
                ->with('organization:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Business $business): array => [
                    'id' => $business->getKey(),
                    'name' => $business->name,
                    'business_type' => $business->business_type->label(),
                    'currency' => $business->currency,
                    'organization' => $business->organization->name,
                    'role' => $user->roleIn($business)?->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('businesses/Create', [
            'businessTypes' => array_map(
                static fn (BusinessType $type): array => ['value' => $type->value, 'label' => $type->label()],
                BusinessType::cases()
            ),
            'accountingBases' => array_map(
                static fn (AccountingBasis $basis): array => ['value' => $basis->value, 'label' => $basis->label()],
                AccountingBasis::cases()
            ),
            'coaTemplates' => CoaTemplate::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['code', 'name', 'business_type'])
                ->map(static fn (CoaTemplate $template): array => [
                    'code' => $template->code,
                    'name' => $template->name,
                    'business_type' => $template->business_type->value,
                ]),
        ]);
    }

    public function store(StoreBusinessRequest $request): RedirectResponse
    {
        $business = $this->provisioning->createBusiness($request->user(), $request->validated());

        return redirect()
            ->route('businesses.show', $business)
            ->with('success', 'Bisnis berhasil dibuat beserta starter chart of accounts.');
    }

    public function show(Business $business): Response
    {
        $this->authorize('view', $business);

        $business->load(['profile', 'organization:id,name', 'bankAccounts']);

        return Inertia::render('businesses/Show', [
            'business' => [
                'id' => $business->getKey(),
                'name' => $business->name,
                'legal_name' => $business->legal_name,
                'business_type' => $business->business_type->label(),
                'accounting_basis' => $business->accounting_basis->label(),
                'currency' => $business->currency,
                'opening_date' => $business->opening_date->toDateString(),
                'organization' => $business->organization->name,
                'profile' => $business->profile?->only([
                    'tax_id', 'phone', 'email', 'website', 'address', 'city', 'province', 'postal_code',
                ]),
                'bank_accounts' => $business->bankAccounts->map(static fn ($account): array => [
                    'id' => $account->getKey(),
                    'label' => $account->label,
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'is_primary' => $account->is_primary,
                ]),
            ],
            'counts' => [
                'accounts' => $business->accounts()->count(),
                'periods' => $business->accountingPeriods()->count(),
                'journal_entries' => $business->journalEntries()->count(),
            ],
        ]);
    }

    public function update(Request $request, Business $business): RedirectResponse
    {
        $this->authorize('update', $business);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:128'],
            'province' => ['nullable', 'string', 'max:128'],
            'postal_code' => ['nullable', 'string', 'max:16'],
        ]);

        $this->provisioning->updateBusiness($business, $validated, $request->user());

        return back()->with('success', 'Profil bisnis diperbarui.');
    }
}

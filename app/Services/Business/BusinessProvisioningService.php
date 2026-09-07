<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Domain\Accounting\Models\CoaTemplate;
use App\Domain\Business\Enums\AccountingBasis;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\BankAccount;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Business\Models\Organization;
use App\Domain\Business\Models\OrganizationUser;
use App\Domain\Business\Models\Role;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Onboarding bisnis (plan.md §5.1).
 *
 * Satu pemanggilan menghasilkan business, profile, membership owner, accounting period,
 * starter COA, dan rekening bank awal, karena plan.md §5.1 memperlakukan semuanya sebagai
 * satu langkah onboarding.
 */
class BusinessProvisioningService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly AccountingPeriodService $periods,
        private readonly MembershipService $memberships,
        private readonly AuditLogger $auditLogger,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Organization default untuk seorang user, dibuat saat dibutuhkan.
     */
    public function ensurePersonalOrganization(User $user, ?string $name = null): Organization
    {
        $existing = Organization::query()->where('owner_id', $user->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $name): Organization {
            $organization = Organization::create([
                'name' => $name ?? $user->name,
                'slug' => $this->uniqueOrganizationSlug($name ?? $user->name),
                'type' => Organization::TYPE_BUSINESS,
                'owner_id' => $user->getKey(),
            ]);

            OrganizationUser::create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role_id' => Role::findBySlug(RoleSlug::BusinessOwner)->getKey(),
                'joined_at' => now(),
            ]);

            $this->auditLogger->log('organization.created', $organization, null, [
                'name' => $organization->name,
                'type' => $organization->type,
            ], actor: $user);

            return $organization;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBusiness(User $owner, array $attributes): Business
    {
        return DB::transaction(function () use ($owner, $attributes): Business {
            $organization = isset($attributes['organization_id'])
                ? Organization::query()->findOrFail($attributes['organization_id'])
                : $this->ensurePersonalOrganization($owner);

            $businessType = BusinessType::from((string) ($attributes['business_type'] ?? BusinessType::General->value));
            $openingDate = Carbon::parse((string) ($attributes['opening_date'] ?? now()))->startOfDay();

            $business = Business::create([
                'organization_id' => $organization->getKey(),
                'name' => (string) $attributes['name'],
                'slug' => $this->uniqueBusinessSlug($organization, (string) $attributes['name']),
                'legal_name' => $attributes['legal_name'] ?? null,
                'business_type' => $businessType->value,
                'currency' => (string) ($attributes['currency'] ?? config('akunta.money.default_currency')),
                'accounting_basis' => (string) ($attributes['accounting_basis'] ?? AccountingBasis::Accrual->value),
                'period_length' => (string) ($attributes['period_length'] ?? config('akunta.accounting.default_period_length')),
                'opening_date' => $openingDate->toDateString(),
                'fiscal_year_start_month' => (string) ($attributes['fiscal_year_start_month'] ?? '01'),
                'timezone' => (string) ($attributes['timezone'] ?? config('app.timezone')),
            ]);

            $this->tenantContext->withBusiness($business, function () use ($business, $attributes): void {
                BusinessProfile::create([
                    'business_id' => $business->getKey(),
                    ...array_intersect_key($attributes, array_flip([
                        'tax_id', 'phone', 'email', 'website', 'address',
                        'city', 'province', 'postal_code', 'country', 'industry_note',
                    ])),
                ]);
            });

            $this->memberships->attach(
                $business,
                $owner,
                RoleSlug::BusinessOwner,
                isExternal: false,
                actor: $owner
            );

            $this->periods->generateMonthlyPeriods($business, (int) $openingDate->format('Y'));

            $this->applyStarterCoa($business, $attributes['coa_template_code'] ?? null);

            $this->createInitialBankAccounts($business, $attributes['bank_accounts'] ?? []);

            $this->auditLogger->log('business.created', $business, null, [
                'name' => $business->name,
                'business_type' => $business->business_type->value,
                'opening_date' => $business->opening_date->toDateString(),
            ], business: $business, actor: $owner);

            return $business->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBusiness(Business $business, array $attributes, User $actor): Business
    {
        return DB::transaction(function () use ($business, $attributes, $actor): Business {
            $business->update(array_intersect_key($attributes, array_flip([
                'name', 'legal_name', 'business_type', 'timezone', 'is_active',
            ])));

            $this->auditLogger->logChanges('business.updated', $business, business: $business, actor: $actor);

            $profileAttributes = array_intersect_key($attributes, array_flip([
                'tax_id', 'phone', 'email', 'website', 'address',
                'city', 'province', 'postal_code', 'country', 'industry_note',
            ]));

            if ($profileAttributes !== []) {
                $this->tenantContext->withBusiness($business, function () use ($business, $profileAttributes, $actor): void {
                    $profile = $business->profile ?? BusinessProfile::create(['business_id' => $business->getKey()]);
                    $profile->update($profileAttributes);

                    $this->auditLogger->logChanges(
                        'business_profile.updated',
                        $profile,
                        business: $business,
                        actor: $actor
                    );
                });
            }

            return $business->refresh();
        });
    }

    private function applyStarterCoa(Business $business, ?string $templateCode): void
    {
        $template = $templateCode !== null
            ? CoaTemplate::query()->where('code', $templateCode)->firstOrFail()
            : CoaTemplate::resolveFor($business->business_type);

        if ($template === null) {
            throw new RuntimeException(
                'Tidak ada COA template aktif. Jalankan CoaTemplateSeeder terlebih dahulu.'
            );
        }

        $this->chartOfAccounts->applyTemplate($business, $template);
    }

    /**
     * @param  array<int, array<string, mixed>>  $bankAccounts
     */
    private function createInitialBankAccounts(Business $business, array $bankAccounts): void
    {
        if ($bankAccounts === []) {
            return;
        }

        $this->tenantContext->withBusiness($business, function () use ($business, $bankAccounts): void {
            foreach (array_values($bankAccounts) as $index => $bankAccount) {
                BankAccount::create([
                    'business_id' => $business->getKey(),
                    'label' => (string) $bankAccount['label'],
                    'bank_name' => (string) $bankAccount['bank_name'],
                    'account_number' => (string) $bankAccount['account_number'],
                    'account_holder' => $bankAccount['account_holder'] ?? null,
                    'currency' => (string) ($bankAccount['currency'] ?? $business->currency),
                    'account_kind' => (string) ($bankAccount['account_kind'] ?? BankAccount::KIND_BANK),
                    'is_primary' => (bool) ($bankAccount['is_primary'] ?? $index === 0),
                    'chart_of_account_id' => $bankAccount['chart_of_account_id'] ?? null,
                ]);
            }
        });
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 1;

        while (Organization::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }

    private function uniqueBusinessSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 1;

        while (Business::query()
            ->withTrashed()
            ->where('organization_id', $organization->getKey())
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}

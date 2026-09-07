<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Business\BusinessProvisioningService;
use App\Services\Business\MembershipService;
use Illuminate\Support\Carbon;

/**
 * Helper provisioning untuk test.
 *
 * Bisnis dibuat lewat BusinessProvisioningService, bukan lewat factory langsung, supaya
 * yang teruji adalah jalur onboarding sebenarnya beserta starter COA dan accounting
 * period-nya (plan.md §5.1).
 */
trait ProvisionsBusinesses
{
    protected function provisionBusiness(
        ?User $owner = null,
        string $name = 'Toko Uji',
        BusinessType $type = BusinessType::Retail,
        string $openingDate = '2026-01-01',
    ): Business {
        $owner ??= User::factory()->create();

        return app(BusinessProvisioningService::class)->createBusiness($owner, [
            'name' => $name,
            'business_type' => $type->value,
            'opening_date' => $openingDate,
        ]);
    }

    /**
     * @return array{0: User, 1: Business}
     */
    protected function provisionBusinessWithOwner(
        string $name = 'Toko Uji',
        BusinessType $type = BusinessType::Retail,
        string $openingDate = '2026-01-01',
    ): array {
        $owner = User::factory()->create();

        return [$owner, $this->provisionBusiness($owner, $name, $type, $openingDate)];
    }

    protected function addMember(
        Business $business,
        RoleSlug $role,
        bool $isExternal = false,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();

        app(MembershipService::class)->attach($business, $user, $role, $isExternal);

        return $user;
    }

    /**
     * Akun bisnis berdasarkan kode COA, dibaca tanpa bergantung pada tenant context
     * aktif agar helper dapat dipakai dari test mana pun.
     */
    protected function account(Business $business, string $code): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->getKey())
            ->where('code', $code)
            ->sole();
    }

    protected function accountByRole(Business $business, AccountRole $role): ChartOfAccount
    {
        return app(TenantContext::class)->withBusiness(
            $business,
            fn (): ChartOfAccount => ChartOfAccount::query()
                ->postable()
                ->withRole($role)
                ->orderBy('code')
                ->firstOrFail()
        );
    }

    protected function periodFor(Business $business, string $date): AccountingPeriod
    {
        return AccountingPeriod::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->getKey())
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->sole();
    }

    /**
     * Journal dua baris yang seimbang, langsung diposting.
     */
    protected function postSimpleJournal(
        Business $business,
        User $actor,
        string $debitCode,
        string $creditCode,
        string $amount,
        string $date = '2026-01-15',
        string $description = 'Journal uji',
    ): JournalEntry {
        return app(JournalPostingService::class)->createAndPost(
            $business,
            new JournalEntryData(
                entryDate: Carbon::parse($date),
                description: $description,
                lines: [
                    new JournalLineData(
                        accountId: $this->account($business, $debitCode)->getKey(),
                        debit: $amount,
                    ),
                    new JournalLineData(
                        accountId: $this->account($business, $creditCode)->getKey(),
                        credit: $amount,
                    ),
                ],
            ),
            $actor
        );
    }
}

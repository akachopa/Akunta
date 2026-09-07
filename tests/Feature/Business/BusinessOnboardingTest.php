<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\BankAccount;
use App\Domain\Business\Models\Business;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Business\BusinessProvisioningService;

/*
 * plan.md §5.1: satu langkah onboarding menghasilkan business, profil, membership owner,
 * accounting period, dan starter COA sesuai jenis usaha.
 */

it('membuat organization personal untuk user baru', function (): void {
    $user = User::factory()->create(['name' => 'Budi Santoso']);

    $organization = app(BusinessProvisioningService::class)->ensurePersonalOrganization($user);

    expect($organization->owner_id)->toBe($user->getKey());
    expect($organization->name)->toBe('Budi Santoso');
});

it('tidak menduplikasi organization personal', function (): void {
    $user = User::factory()->create();
    $service = app(BusinessProvisioningService::class);

    $first = $service->ensurePersonalOrganization($user);
    $second = $service->ensurePersonalOrganization($user);

    expect($second->getKey())->toBe($first->getKey());
});

it('menjadikan pembuat bisnis sebagai business owner', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    expect($owner->roleIn($business)?->slug)->toBe(RoleSlug::BusinessOwner);
    expect($owner->membershipFor($business)->is_external)->toBeFalse();
});

it('membuat profil bisnis dan dua belas periode bulanan', function (): void {
    [, $business] = $this->provisionBusinessWithOwner(openingDate: '2026-03-10');

    expect($business->profile)->not->toBeNull();

    $periods = AccountingPeriod::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())
        ->orderBy('period_number')
        ->get();

    expect($periods)->toHaveCount(12);
    expect($periods->first()->start_date->toDateString())->toBe('2026-01-01');
    expect($periods->last()->end_date->toDateString())->toBe('2026-12-31');
    expect($periods->pluck('status.value')->unique()->all())->toBe(['open']);
});

it('men-generate starter chart of accounts dari template jenis usaha', function (): void {
    [, $retail] = $this->provisionBusinessWithOwner('Toko Retail', BusinessType::Retail);
    [, $service] = $this->provisionBusinessWithOwner('Jasa Konsultan', BusinessType::Service);

    $retailAccounts = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $retail->getKey())->get();

    expect($retailAccounts)->not->toBeEmpty();
    expect($retailAccounts->pluck('is_system')->unique()->all())->toBe([true]);

    /*
     * plan.md §12.1: template retail memuat persediaan dan HPP; template jasa tidak
     * wajib memuat keduanya.
     */
    expect($retailAccounts->pluck('account_role'))->toContain(AccountRole::Inventory);

    $serviceRoles = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $service->getKey())
        ->pluck('account_role');

    expect($serviceRoles)->toContain(AccountRole::ServiceRevenue);
});

it('menyusun hierarki akun dari template tanpa parent menggantung', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $accounts = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())
        ->get();

    $ids = $accounts->pluck('id')->all();

    foreach ($accounts->whereNotNull('parent_id') as $account) {
        expect($ids)->toContain($account->parent_id);
    }

    expect($accounts->whereNotNull('parent_id'))->not->toBeEmpty();
});

it('memberi setiap akun starter satu account role yang cocok dengan tipenya', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $accounts = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())
        ->whereNotNull('account_role')
        ->get();

    /*
     * Akun kontra sengaja memiliki normal balance berlawanan dengan tipenya: akumulasi
     * penyusutan mengurangi aset, retur penjualan mengurangi pendapatan.
     */
    $contra = [AccountRole::AccumulatedDepreciation, AccountRole::SalesReturn];

    foreach ($accounts as $account) {
        expect($account->account_role->expectedAccountType())->toBe($account->account_type);

        if (in_array($account->account_role, $contra, true)) {
            expect($account->normal_balance)->not->toBe($account->account_type->normalBalance());

            continue;
        }

        expect($account->normal_balance)->toBe($account->account_type->normalBalance());
    }
});

it('membuat rekening awal saat onboarding', function (): void {
    $owner = User::factory()->create();

    $business = app(BusinessProvisioningService::class)->createBusiness($owner, [
        'name' => 'Toko Dengan Rekening',
        'business_type' => BusinessType::Retail->value,
        'opening_date' => '2026-01-01',
        'bank_accounts' => [
            ['label' => 'BCA Operasional', 'bank_name' => 'BCA', 'account_number' => '1234567890'],
            ['label' => 'Mandiri Cadangan', 'bank_name' => 'Mandiri', 'account_number' => '9876543210'],
        ],
    ]);

    $accounts = BankAccount::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())
        ->get();

    expect($accounts)->toHaveCount(2);
    expect($accounts->where('is_primary', true))->toHaveCount(1);
});

it('membuat bisnis lewat HTTP dan mengarahkan ke workspace baru', function (): void {
    $owner = User::factory()->create();

    $response = $this->actingAs($owner)->post('/businesses', [
        'name' => 'Warung Makan Sederhana',
        'business_type' => BusinessType::Restaurant->value,
        'opening_date' => '2026-01-01',
        'currency' => 'IDR',
    ]);

    $business = Business::query()->where('name', 'Warung Makan Sederhana')->sole();

    $response->assertRedirect("/businesses/{$business->getKey()}");
    expect($owner->belongsToBusiness($business))->toBeTrue();
    expect(ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())->count())->toBeGreaterThan(0);
});

it('menolak pembuatan bisnis tanpa nama', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/businesses', ['business_type' => BusinessType::Retail->value])
        ->assertSessionHasErrors('name');
});

it('menerapkan template secara idempoten', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $before = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())->count();

    $template = $business->accounts()->first()->template;
    app(ChartOfAccountsService::class)->applyTemplate($business, $template);

    $after = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $business->getKey())->count();

    expect($after)->toBe($before);
});

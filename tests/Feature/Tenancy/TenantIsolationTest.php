<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Tenancy\Exceptions\CrossTenantWriteAttempt;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/*
 * plan.md §30 (tenant isolation) dan §44.15 ("jangan mengizinkan cross-tenant access").
 */

it('memfilter query model bertenant ke business aktif', function (): void {
    [, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    $context = app(TenantContext::class);

    $countA = $context->withBusiness($businessA, fn (): int => ChartOfAccount::query()->count());
    $countB = $context->withBusiness($businessB, fn (): int => ChartOfAccount::query()->count());

    expect($countA)->toBeGreaterThan(0);
    expect($countA)->toBe($countB);

    $businessIdsSeenByA = $context->withBusiness(
        $businessA,
        fn (): array => ChartOfAccount::query()->pluck('business_id')->unique()->all()
    );

    expect($businessIdsSeenByA)->toBe([$businessA->getKey()]);
});

it('menyembunyikan record tenant lain dari find', function (): void {
    [, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    $accountOfB = $this->account($businessB, '1101');

    $found = app(TenantContext::class)->withBusiness(
        $businessA,
        fn (): ?ChartOfAccount => ChartOfAccount::query()->find($accountOfB->getKey())
    );

    expect($found)->toBeNull();
});

it('menolak menulis record milik tenant lain', function (): void {
    [, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    app(TenantContext::class)->withBusiness($businessA, function () use ($businessB): void {
        expect(fn (): ChartOfAccount => ChartOfAccount::create([
            'business_id' => $businessB->getKey(),
            'code' => '9999',
            'name' => 'Akun Selundupan',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'reporting_group' => 'current_asset',
        ]))->toThrow(CrossTenantWriteAttempt::class);
    });

    expect(ChartOfAccount::query()->withoutGlobalScopes()->where('code', '9999')->exists())
        ->toBeFalse();
});

it('mengembalikan 404 ketika user bukan member business', function (): void {
    $outsider = User::factory()->create();
    [, $business] = $this->provisionBusinessWithOwner('Bisnis Tertutup');

    /*
     * 404, bukan 403: keberadaan business milik tenant lain tidak boleh dapat
     * disimpulkan dari respons.
     */
    $this->actingAs($outsider)->get("/businesses/{$business->getKey()}")->assertNotFound();
    $this->actingAs($outsider)->get("/businesses/{$business->getKey()}/accounts")->assertNotFound();
    $this->actingAs($outsider)->get("/businesses/{$business->getKey()}/journals")->assertNotFound();
    $this->actingAs($outsider)->get("/businesses/{$business->getKey()}/periods")->assertNotFound();
    $this->actingAs($outsider)
        ->get("/businesses/{$business->getKey()}/reports/trial-balance")
        ->assertNotFound();
});

it('mengembalikan 404 ketika child record milik business lain', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    $accountOfB = $this->account($businessB, '1101');

    /*
     * scopeBindings() memaksa resolusi child lewat relasi business, sehingga id valid
     * milik tenant lain tetap menghasilkan 404.
     */
    $this->actingAs($ownerA)
        ->patch("/businesses/{$businessA->getKey()}/accounts/{$accountOfB->getKey()}", ['name' => 'Diubah'])
        ->assertNotFound();

    expect($accountOfB->refresh()->name)->not->toBe('Diubah');
});

it('tidak membocorkan journal entry lintas business', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    $accountantB = $this->addMember($businessB, RoleSlug::Accountant);
    $entryOfB = $this->postSimpleJournal($businessB, $accountantB, '1101', '4100', '100000');

    $this->actingAs($ownerA)
        ->get("/businesses/{$businessA->getKey()}/journals/{$entryOfB->getKey()}")
        ->assertNotFound();

    $visibleToA = app(TenantContext::class)->withBusiness(
        $businessA,
        fn (): int => JournalEntry::query()->count()
    );

    expect($visibleToA)->toBe(0);
    expect($ownerB->belongsToBusiness($businessB))->toBeTrue();
});

it('menolak query bertenant tanpa context hanya jika scope dilewati secara sadar', function (): void {
    [, $business] = $this->provisionBusinessWithOwner('Bisnis A');

    $context = app(TenantContext::class);
    $context->forget();

    /*
     * Tanpa tenant aktif, global scope tidak punya nilai untuk difilter. Kondisi ini
     * hanya terjadi di jalur non-HTTP (seeder, job platform), dan withoutTenant()
     * adalah satu-satunya cara eksplisit untuk melintasi tenant.
     */
    expect($context->shouldScopeQueries())->toBeFalse();

    $all = $context->withoutTenant(fn (): int => ChartOfAccount::query()->count());

    expect($all)->toBeGreaterThan(0);
    expect($business->accounts()->count())->toBeGreaterThan(0);
});

<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Enums\BusinessType;
use App\Models\User;

/*
 * plan.md §37 Phase 1 acceptance: "1 user dapat memiliki beberapa bisnis".
 */

it('mengizinkan satu user memiliki beberapa bisnis', function (): void {
    $owner = User::factory()->create();

    $retail = $this->provisionBusiness($owner, 'Toko Retail', BusinessType::Retail);
    $service = $this->provisionBusiness($owner, 'Jasa Konsultan', BusinessType::Service);

    expect($owner->businesses()->pluck('name')->all())
        ->toEqualCanonicalizing(['Toko Retail', 'Jasa Konsultan']);

    expect($retail->organization_id)->toBe($service->organization_id);
});

it('memberi setiap bisnis chart of accounts dan periode sendiri', function (): void {
    $owner = User::factory()->create();

    $first = $this->provisionBusiness($owner, 'Bisnis Pertama');
    $second = $this->provisionBusiness($owner, 'Bisnis Kedua');

    $firstAccounts = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $first->getKey())->count();
    $secondAccounts = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $second->getKey())->count();

    expect($firstAccounts)->toBeGreaterThan(0);
    expect($secondAccounts)->toBeGreaterThan(0);

    // Tidak ada satu pun akun yang dibagi antar bisnis.
    expect(ChartOfAccount::query()->withoutGlobalScopes()
        ->where('business_id', $first->getKey())
        ->pluck('id')
        ->intersect(
            ChartOfAccount::query()->withoutGlobalScopes()
                ->where('business_id', $second->getKey())
                ->pluck('id')
        )
    )->toBeEmpty();

    expect(AccountingPeriod::query()->withoutGlobalScopes()
        ->where('business_id', $first->getKey())->count())->toBe(12);
});

it('memakai slug unik per organization walau nama bisnis sama', function (): void {
    $owner = User::factory()->create();

    $first = $this->provisionBusiness($owner, 'Toko Sama');
    $second = $this->provisionBusiness($owner, 'Toko Sama');

    expect($second->slug)->not->toBe($first->slug);
});

it('menampilkan semua bisnis milik user pada daftar bisnis', function (): void {
    $owner = User::factory()->create();

    $this->provisionBusiness($owner, 'Bisnis A');
    $this->provisionBusiness($owner, 'Bisnis B');

    // Bisnis milik user lain tidak boleh muncul.
    $this->provisionBusiness(User::factory()->create(), 'Bisnis Orang Lain');

    $response = $this->actingAs($owner)->get('/businesses');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('businesses/Index')
        ->has('businesses', 2)
    );
});

<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Exceptions\InvalidAccountForPosting;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Enums\RoleSlug;
use App\Services\Accounting\ChartOfAccountsService;

/*
 * plan.md §33.1 mewajibkan unit test COA behavior; aturan akunnya di plan.md §12.2.
 */

it('membuat akun custom dengan normal balance turunan dari tipe akun', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $account = app(ChartOfAccountsService::class)->createAccount($business, [
        'code' => '6910',
        'name' => 'Beban Pelatihan Karyawan',
        'account_type' => AccountType::OperatingExpense->value,
    ], $owner);

    expect($account->normal_balance)->toBe(NormalBalance::Debit);
    expect($account->is_system)->toBeFalse();
    expect($account->is_postable)->toBeTrue();
    expect($account->business_id)->toBe($business->getKey());
});

it('menolak account role yang tidak cocok dengan account type', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    expect(fn (): ChartOfAccount => app(ChartOfAccountsService::class)->createAccount($business, [
        'code' => '6920',
        'name' => 'Akun Salah Role',
        'account_type' => AccountType::OperatingExpense->value,
        'account_role' => AccountRole::CashOrBank->value,
    ], $owner))->toThrow(RuntimeException::class);
});

it('menolak kode akun duplikat dalam satu bisnis', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    expect(fn (): ChartOfAccount => app(ChartOfAccountsService::class)->createAccount($business, [
        'code' => '1101',
        'name' => 'Kas Duplikat',
        'account_type' => AccountType::Asset->value,
    ], $owner))->toThrow(Illuminate\Database\QueryException::class);
});

it('mengizinkan kode akun sama pada bisnis berbeda', function (): void {
    [, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    expect($this->account($businessA, '1101')->code)->toBe('1101');
    expect($this->account($businessB, '1101')->code)->toBe('1101');
    expect($this->account($businessA, '1101')->getKey())
        ->not->toBe($this->account($businessB, '1101')->getKey());
});

it('membatasi perubahan akun sistem pada nama dan status', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $account = $this->account($business, '1101');

    app(ChartOfAccountsService::class)->updateAccount($account, [
        'name' => 'Kas Besar',
        'account_type' => AccountType::Revenue->value,
        'code' => '9999',
    ], $owner);

    $account->refresh();

    expect($account->name)->toBe('Kas Besar');

    /*
     * Tipe dan kode akun sistem tidak berubah: mengubah tipe akun yang sudah bersaldo
     * akan memindahkan angka antar laporan tanpa jejak journal.
     */
    expect($account->account_type)->toBe(AccountType::Asset);
    expect($account->code)->toBe('1101');
});

it('menonaktifkan akun alih-alih menghapusnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $account = $this->account($business, '1400');

    app(ChartOfAccountsService::class)->deactivateAccount($account, $owner);

    expect($account->refresh()->is_active)->toBeFalse();
    expect(ChartOfAccount::query()->withoutGlobalScopes()->whereKey($account->getKey())->exists())
        ->toBeTrue();
});

it('menolak posting ke akun header', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $accountant = $this->addMember($business, RoleSlug::Accountant);

    $header = $this->account($business, '1');

    expect($header->is_postable)->toBeFalse();
    expect(fn () => $this->postSimpleJournal($business, $accountant, '1', '4100', '100000'))
        ->toThrow(InvalidAccountForPosting::class);
});

it('menolak posting ke akun nonaktif', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $accountant = $this->addMember($business, RoleSlug::Accountant);

    app(ChartOfAccountsService::class)->deactivateAccount($this->account($business, '1400'), $owner);

    expect(fn () => $this->postSimpleJournal($business, $accountant, '1400', '1102', '100000'))
        ->toThrow(InvalidAccountForPosting::class);
});

it('me-resolve akun berdasarkan account role', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $service = app(ChartOfAccountsService::class);

    expect($service->resolveByRoleOrFail($business, AccountRole::AccountsReceivable)->code)->toBe('1200');
    expect($service->resolveByRoleOrFail($business, AccountRole::AccountsPayable)->code)->toBe('2100');
    expect($service->resolveByRoleOrFail($business, AccountRole::SalesRevenue)->code)->toBe('4100');
});

it('menambah akun lewat HTTP dan menolak akses tanpa permission', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    $payload = [
        'code' => '6930',
        'name' => 'Beban Keamanan',
        'account_type' => AccountType::OperatingExpense->value,
    ];

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/accounts", $payload)
        ->assertForbidden();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/accounts", $payload)
        ->assertRedirect();

    expect($this->account($business, '6930')->name)->toBe('Beban Keamanan');
});

it('menampilkan chart of accounts pada halaman Inertia', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/accounts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/ChartOfAccounts')
            ->has('accounts')
            ->has('accountTypes', count(AccountType::cases()))
        );
});

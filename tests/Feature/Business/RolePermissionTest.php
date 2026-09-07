<?php

declare(strict_types=1);

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\Permission;
use App\Domain\Business\Models\Role;
use App\Models\User;

/*
 * plan.md §33.1 mewajibkan unit test permissions, dengan pembagian hak pada plan.md §4.
 */

it('men-seed seluruh role dan permission', function (): void {
    expect(Role::query()->pluck('slug')->map->value->all())
        ->toEqualCanonicalizing(RoleSlug::values());

    expect(Permission::query()->pluck('slug')->map->value->all())
        ->toEqualCanonicalizing(PermissionSlug::values());
});

it('memetakan permission role sesuai definisi enum', function (): void {
    foreach (RoleSlug::cases() as $slug) {
        $role = Role::findBySlug($slug);

        expect($role->permissions->pluck('slug')->map->value->all())
            ->toEqualCanonicalizing(array_map(
                static fn (PermissionSlug $permission): string => $permission->value,
                $slug->permissions()
            ));
    }
});

it('tidak memberi permission apa pun kepada non-member', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $outsider = User::factory()->create();

    foreach (PermissionSlug::cases() as $permission) {
        expect($outsider->hasBusinessPermission($business, $permission))->toBeFalse();
    }
});

it('memberi owner hak kelola bisnis tetapi bukan hak posting journal', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    expect($owner->hasBusinessPermission($business, PermissionSlug::BusinessManage))->toBeTrue();
    expect($owner->hasBusinessPermission($business, PermissionSlug::MemberManage))->toBeTrue();
    expect($owner->hasBusinessPermission($business, PermissionSlug::JournalApprove))->toBeTrue();
    expect($owner->hasBusinessPermission($business, PermissionSlug::TransactionReview))->toBeTrue();
    expect($owner->hasBusinessPermission($business, PermissionSlug::TransactionApprove))->toBeTrue();
    expect($owner->hasBusinessPermission($business, PermissionSlug::PeriodClose))->toBeTrue();

    /*
     * plan.md §4.2 memberi owner "approve transaksi jika diizinkan" dan "initiate
     * closing", sementara posting, reversal, dan reopen period adalah hak
     * accountant/reviewer pada plan.md §4.4.
     */
    expect($owner->hasBusinessPermission($business, PermissionSlug::JournalPost))->toBeFalse();
    expect($owner->hasBusinessPermission($business, PermissionSlug::JournalReverse))->toBeFalse();
    expect($owner->hasBusinessPermission($business, PermissionSlug::PeriodReopen))->toBeFalse();
});

it('membatasi staff pada upload dan pembuatan journal draft', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    expect($staff->hasBusinessPermission($business, PermissionSlug::JournalCreate))->toBeTrue();
    expect($staff->hasBusinessPermission($business, PermissionSlug::AccountView))->toBeTrue();
    expect($staff->hasBusinessPermission($business, PermissionSlug::TransactionView))->toBeTrue();

    expect($staff->hasBusinessPermission($business, PermissionSlug::TransactionReview))->toBeFalse();
    expect($staff->hasBusinessPermission($business, PermissionSlug::TransactionApprove))->toBeFalse();
    expect($staff->hasBusinessPermission($business, PermissionSlug::JournalApprove))->toBeFalse();
    expect($staff->hasBusinessPermission($business, PermissionSlug::JournalPost))->toBeFalse();
    expect($staff->hasBusinessPermission($business, PermissionSlug::AccountManage))->toBeFalse();
    expect($staff->hasBusinessPermission($business, PermissionSlug::ReportView))->toBeFalse();
    expect($staff->hasBusinessPermission($business, PermissionSlug::BusinessManage))->toBeFalse();
});

it('memberi platform admin akses lintas tenant', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $admin = User::factory()->platformAdmin()->create();

    expect($admin->belongsToBusiness($business))->toBeFalse();

    foreach (PermissionSlug::cases() as $permission) {
        expect($admin->hasBusinessPermission($business, $permission))->toBeTrue();
    }
});

it('memblokir staff dari halaman laporan', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    $this->actingAs($staff)
        ->get("/businesses/{$business->getKey()}/reports/trial-balance")
        ->assertForbidden();
});

it('membagikan daftar permission ke frontend melalui Inertia', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $response = $this->actingAs($owner)->get("/businesses/{$business->getKey()}");

    $response->assertInertia(fn ($page) => $page
        ->where('currentBusiness.id', $business->getKey())
        ->has('permissions')
    );

    expect($response->viewData('page')['props']['permissions'])
        ->toEqualCanonicalizing(array_map(
            static fn (PermissionSlug $permission): string => $permission->value,
            RoleSlug::BusinessOwner->permissions()
        ));
});

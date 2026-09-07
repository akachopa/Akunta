<?php

declare(strict_types=1);

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\BusinessUser;
use App\Models\User;
use App\Services\Business\MembershipService;

/*
 * plan.md §37 Phase 1: members dan roles.
 */

it('melekatkan user sebagai staff', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    expect($staff->roleIn($business)?->slug)->toBe(RoleSlug::BusinessStaff);
});

it('mengubah role member yang sudah ada alih-alih menduplikasi keanggotaan', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $user = User::factory()->create();
    $service = app(MembershipService::class);

    $service->attach($business, $user, RoleSlug::BusinessStaff);
    $service->attach($business, $user, RoleSlug::Accountant);

    expect(BusinessUser::query()
        ->where('business_id', $business->getKey())
        ->where('user_id', $user->getKey())
        ->count())->toBe(1);

    expect($user->roleIn($business)?->slug)->toBe(RoleSlug::Accountant);
});

it('menolak melekatkan role platform admin ke business', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    expect(fn (): BusinessUser => app(MembershipService::class)
        ->attach($business, User::factory()->create(), RoleSlug::PlatformAdmin))
        ->toThrow(RuntimeException::class);
});

it('menolak melepas owner terakhir', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $membership = $owner->membershipFor($business);

    expect(fn () => app(MembershipService::class)->detach($membership))
        ->toThrow(RuntimeException::class);

    expect($owner->fresh()->belongsToBusiness($business))->toBeTrue();
});

it('mengizinkan melepas owner ketika masih ada owner lain', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $secondOwner = $this->addMember($business, RoleSlug::BusinessOwner);

    app(MembershipService::class)->detach($owner->membershipFor($business));

    expect($owner->fresh()->belongsToBusiness($business))->toBeFalse();
    expect($secondOwner->fresh()->belongsToBusiness($business))->toBeTrue();
});

it('menambah member lewat HTTP berdasarkan email', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $calon = User::factory()->create(['email' => 'akuntan@akunta.test']);

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/members", [
            'email' => 'akuntan@akunta.test',
            'role' => RoleSlug::Accountant->value,
            'is_external' => true,
        ])
        ->assertRedirect();

    expect($calon->fresh()->roleIn($business)?->slug)->toBe(RoleSlug::Accountant);
});

it('menolak menambah member dengan email yang belum terdaftar', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/members", [
            'email' => 'belum-ada@akunta.test',
            'role' => RoleSlug::BusinessStaff->value,
        ])
        ->assertSessionHasErrors('email');
});

it('menolak staff mengelola member', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/members", [
            'email' => User::factory()->create()->email,
            'role' => RoleSlug::BusinessStaff->value,
        ])
        ->assertForbidden();
});

it('menolak mengubah member milik business lain', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner('Bisnis A');
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Bisnis B');

    $membershipB = $ownerB->membershipFor($businessB);

    $this->actingAs($ownerA)
        ->patch("/businesses/{$businessA->getKey()}/members/{$membershipB->getKey()}", [
            'role' => RoleSlug::BusinessStaff->value,
        ])
        ->assertNotFound();

    expect($ownerB->fresh()->roleIn($businessB)?->slug)->toBe(RoleSlug::BusinessOwner);
});

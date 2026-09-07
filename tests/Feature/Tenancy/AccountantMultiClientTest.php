<?php

declare(strict_types=1);

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Enums\RoleSlug;
use App\Models\User;

/*
 * plan.md §37 Phase 1 acceptance: "accountant dapat mengakses beberapa client",
 * dan plan.md §3.1 "1 akuntan dapat mengakses banyak bisnis".
 */

it('mengizinkan satu accountant menjadi member beberapa bisnis milik pemilik berbeda', function (): void {
    $accountant = User::factory()->create();

    [, $clientA] = $this->provisionBusinessWithOwner('Client A');
    [, $clientB] = $this->provisionBusinessWithOwner('Client B');
    [, $clientC] = $this->provisionBusinessWithOwner('Client C');

    foreach ([$clientA, $clientB, $clientC] as $client) {
        $this->addMember($client, RoleSlug::Accountant, isExternal: true, user: $accountant);
    }

    expect($accountant->businesses()->pluck('name')->all())
        ->toEqualCanonicalizing(['Client A', 'Client B', 'Client C']);

    expect($accountant->businesses()->get()->pluck('organization_id')->unique())->toHaveCount(3);
});

it('menandai accountant sebagai member eksternal', function (): void {
    $accountant = User::factory()->create();
    [, $client] = $this->provisionBusinessWithOwner('Client Eksternal');

    $this->addMember($client, RoleSlug::Accountant, isExternal: true, user: $accountant);

    expect($accountant->membershipFor($client)->is_external)->toBeTrue();
});

it('memberi accountant permission accounting penuh pada setiap client', function (): void {
    $accountant = User::factory()->create();

    [, $clientA] = $this->provisionBusinessWithOwner('Client A');
    [, $clientB] = $this->provisionBusinessWithOwner('Client B');

    $this->addMember($clientA, RoleSlug::Accountant, isExternal: true, user: $accountant);
    $this->addMember($clientB, RoleSlug::Accountant, isExternal: true, user: $accountant);

    foreach ([$clientA, $clientB] as $client) {
        expect($accountant->hasBusinessPermission($client, PermissionSlug::JournalPost))->toBeTrue();
        expect($accountant->hasBusinessPermission($client, PermissionSlug::JournalReverse))->toBeTrue();
        expect($accountant->hasBusinessPermission($client, PermissionSlug::PeriodClose))->toBeTrue();
        expect($accountant->hasBusinessPermission($client, PermissionSlug::PeriodReopen))->toBeTrue();
    }
});

it('mengizinkan accountant membuka workspace setiap client lewat HTTP', function (): void {
    $accountant = User::factory()->create();

    [, $clientA] = $this->provisionBusinessWithOwner('Client A');
    [, $clientB] = $this->provisionBusinessWithOwner('Client B');

    $this->addMember($clientA, RoleSlug::Accountant, isExternal: true, user: $accountant);
    $this->addMember($clientB, RoleSlug::Accountant, isExternal: true, user: $accountant);

    $this->actingAs($accountant)->get("/businesses/{$clientA->getKey()}")->assertOk();
    $this->actingAs($accountant)->get("/businesses/{$clientB->getKey()}")->assertOk();
});

it('membatasi accountant hanya pada client yang menugaskannya', function (): void {
    $accountant = User::factory()->create();

    [, $client] = $this->provisionBusinessWithOwner('Client Ditugaskan');
    [, $bukanClient] = $this->provisionBusinessWithOwner('Bukan Client');

    $this->addMember($client, RoleSlug::Accountant, isExternal: true, user: $accountant);

    $this->actingAs($accountant)->get("/businesses/{$client->getKey()}")->assertOk();
    $this->actingAs($accountant)->get("/businesses/{$bukanClient->getKey()}")->assertNotFound();
});

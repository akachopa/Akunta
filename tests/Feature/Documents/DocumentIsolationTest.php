<?php

declare(strict_types=1);

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

/**
 * plan.md §30 tenant isolation dan §44.15 larangan cross-tenant access.
 *
 * Respons yang diharapkan adalah 404, bukan 403: keberadaan dokumen milik tenant lain
 * tidak boleh dapat disimpulkan dari respons.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menyembunyikan dokumen tenant lain dari query bertenant', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner('Toko A');
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Toko B');

    $this->ingestDocument($businessA, $ownerA, $this->csvFile('a.csv'));
    $this->ingestDocument($businessB, $ownerB, $this->csvFile('b.csv'));

    $visible = app(TenantContext::class)->withBusiness(
        $businessA,
        fn () => Document::query()->pluck('original_filename')->all()
    );

    expect($visible)->toBe(['a.csv']);
});

it('menolak akses dokumen tenant lain dengan 404', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner('Toko A');
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Toko B');

    $documentB = $this->ingestDocument($businessB, $ownerB);

    // Route bisnis milik sendiri, tetapi id dokumen milik tenant lain.
    $this->actingAs($ownerA)
        ->get("/businesses/{$businessA->getKey()}/documents/{$documentB->getKey()}")
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->get("/businesses/{$businessA->getKey()}/documents/{$documentB->getKey()}/download")
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->post("/businesses/{$businessA->getKey()}/documents/{$documentB->getKey()}/reprocess")
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->post("/businesses/{$businessA->getKey()}/documents/{$documentB->getKey()}/archive")
        ->assertNotFound();
});

it('menolak akses inbox bisnis yang bukan miliknya dengan 404', function (): void {
    [$ownerA] = $this->provisionBusinessWithOwner('Toko A');
    [, $businessB] = $this->provisionBusinessWithOwner('Toko B');

    $this->actingAs($ownerA)
        ->get("/businesses/{$businessB->getKey()}/documents")
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->post("/businesses/{$businessB->getKey()}/documents", ['files' => [$this->csvFile()]])
        ->assertNotFound();

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('memisahkan key storage per bisnis', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner('Toko A');
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Toko B');

    $documentA = $this->ingestDocument($businessA, $ownerA);
    $documentB = $this->ingestDocument($businessB, $ownerB);

    /*
     * Partisi per bisnis membuat retensi dan penghapusan data per tenant dapat dijalankan
     * tanpa menyentuh dokumen tenant lain.
     */
    expect($documentA->originalFile->path)->toStartWith("businesses/{$businessA->getKey()}/");
    expect($documentB->originalFile->path)->toStartWith("businesses/{$businessB->getKey()}/");
});

it('mengizinkan accountant mengakses inbox setiap klien yang menjadi tanggungannya', function (): void {
    [, $clientA] = $this->provisionBusinessWithOwner('Klien A');
    [, $clientB] = $this->provisionBusinessWithOwner('Klien B');
    [, $clientC] = $this->provisionBusinessWithOwner('Klien C');

    // plan.md §5.1: satu accountant menangani beberapa klien.
    $accountant = $this->addMember($clientA, RoleSlug::Accountant, isExternal: true);
    $this->addMember($clientB, RoleSlug::Accountant, isExternal: true, user: $accountant);

    foreach ([$clientA, $clientB] as $client) {
        $this->actingAs($accountant)
            ->get("/businesses/{$client->getKey()}/documents")
            ->assertOk();
    }

    // Klien yang bukan tanggungannya tetap tidak terlihat.
    $this->actingAs($accountant)
        ->get("/businesses/{$clientC->getKey()}/documents")
        ->assertNotFound();
});

it('menolak dokumen menyeberang tenant lewat api', function (): void {
    [$ownerA] = $this->provisionBusinessWithOwner('Toko A');
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Toko B');

    $documentB = $this->ingestDocument($businessB, $ownerB);

    $this->actingAs($ownerA, 'sanctum')
        ->getJson("/api/v1/documents/{$documentB->getKey()}")
        ->assertNotFound();

    $this->actingAs($ownerA, 'sanctum')
        ->postJson("/api/v1/documents/{$documentB->getKey()}/reprocess")
        ->assertNotFound();
});

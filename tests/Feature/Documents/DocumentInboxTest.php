<?php

declare(strict_types=1);

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Services\DocumentProcessing\DocumentProcessingService;
use App\Services\DocumentProcessing\DocumentStorage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * plan.md §6 struktur menu Inbox, §35.2 "Inbox First", dan §37 Phase 3 (status polling,
 * document viewer).
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

/**
 * Mengubah status dokumen langsung, dipakai untuk menyiapkan isi tiap tab inbox.
 */
function forceStatus(Document $document, DocumentStatus $status): Document
{
    $document->forceFill(['processing_status' => $status->value])->saveQuietly();

    return $document->refresh();
}

it('menampilkan inbox dengan tab dan jumlah dokumen per tab', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    forceStatus($this->ingestDocument($business, $owner, $this->csvFile('a.csv')), DocumentStatus::Classifying);
    forceStatus($this->ingestDocument($business, $owner, $this->csvFile('b.csv')), DocumentStatus::Failed);
    forceStatus($this->ingestDocument($business, $owner, $this->csvFile('c.csv')), DocumentStatus::NeedReview);
    forceStatus($this->ingestDocument($business, $owner, $this->csvFile('d.csv')), DocumentStatus::Archived);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documents/Index')
            ->where('filter', 'all')

            // plan.md §6: All Documents, Processing, Need Information, Failed, Archived.
            ->where('filters.0.value', 'all')
            ->where('filters.1.value', 'processing')
            ->where('filters.2.value', 'need-information')
            ->where('filters.3.value', 'failed')
            ->where('filters.4.value', 'archived')

            // Tab "All Documents" mengecualikan arsip agar jumlahnya tidak dihitung dua kali.
            ->where('counts.all', 3)
            ->where('counts.processing', 1)
            ->where('counts.need-information', 1)
            ->where('counts.failed', 1)
            ->where('counts.archived', 1)
            ->count('documents.data', 3)
        );
});

it('menyaring dokumen di sisi server sesuai tab', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    forceStatus($this->ingestDocument($business, $owner, $this->csvFile('gagal.csv')), DocumentStatus::Failed);
    forceStatus($this->ingestDocument($business, $owner, $this->pdfFile('tidak-didukung.pdf')), DocumentStatus::Unsupported);
    forceStatus($this->ingestDocument($business, $owner, $this->csvFile('siap.csv')), DocumentStatus::Classifying);

    // plan.md §40 mewajibkan server-side filter.
    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents?filter=failed")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // UNSUPPORTED ditampilkan bersama FAILED karena keduanya menuntut tindakan user.
            ->count('documents.data', 2)
            ->where('filter', 'failed')
        );
});

it('memuat ulang dengan filter default ketika tab tidak dikenal', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents?filter=tidak-ada")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filter', 'all'));
});

it('memaparkan status pipeline yang dapat dipolling klien', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    // plan.md §37 Phase 3 acceptance: "processing status real-time/polling".
    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents")
        ->assertInertia(fn ($page) => $page
            ->where('documents.data.0.processing_status', 'queued')
            ->where('documents.data.0.is_processing', true)
        );

    $this->fakeParserSuccess();

    app(TenantContext::class)->withBusiness(
        $business,
        fn () => app(DocumentProcessingService::class)->process($document)
    );

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents")
        ->assertInertia(fn ($page) => $page
            ->where('documents.data.0.processing_status', 'classifying')
            ->where('documents.data.0.is_processing', true)
            ->where('documents.data.0.page_count', 1)
        );
});

it('menampilkan viewer dengan halaman dan riwayat proses', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();

    app(TenantContext::class)->withBusiness(
        $business,
        fn () => app(DocumentProcessingService::class)->process($document)
    );

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documents/Show')
            ->where('document.reference', 'DOC-000001')
            ->where('document.checksum_sha256', $document->checksum_sha256)
            ->where('document.uploaded_by', $owner->name)
            ->count('document.pages', 1)
            ->where('document.pages.0.is_tabular', true)
            ->where('document.pages.0.row_count', 2)

            /*
             * Timeline memuat tahap parse yang selesai dan dua tahap phase berikutnya
             * (normalize, match). Classify dan extract tidak muncul sebagai baris menunggu
             * karena keduanya sudah dibangun dan job-nya dibuat saat tahapnya dijalankan.
             */
            ->count('document.processing_jobs', 3)
            ->where('document.processing_jobs.0.stage', 'parse')
            ->where('document.processing_jobs.0.status', 'succeeded')
            ->where('can.reprocess', true)
            ->where('can.download', true)
        );
});

it('mengarahkan unduhan ke signed url berumur pendek', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    /*
     * plan.md §30: private object storage + signed URL. Berkas tidak pernah berada di
     * public storage (plan.md §44.11), jadi satu-satunya jalur akses adalah route ini
     * setelah policy lolos.
     */
    $response = $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}/download");

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');

    expect($location)->toContain($document->originalFile->path);
    expect($location)->toContain('expiration=');
});

it('menstream berkas ketika disk tidak mendukung signed url', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    /*
     * Disk lokal pada environment pengembangan tidak dapat membuat signed URL. Berkasnya
     * harus tetap dapat diunduh lewat streaming terautentikasi, bukan dengan membuka
     * storage ke publik.
     */
    $this->app->bind(DocumentStorage::class, fn (): DocumentStorage => new class extends DocumentStorage
    {
        public function temporaryUrl(App\Domain\Documents\Models\DocumentFile $file, ?int $ttlSeconds = null): ?string
        {
            return null;
        }
    });

    $response = $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}/download");

    $response->assertOk();
    $response->assertDownload($document->original_filename);
});

it('menolak unduhan ketika berkas asli hilang dari storage', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    Storage::disk('documents')->delete($document->originalFile->path);

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}/download")
        ->assertRedirect();
});

it('mengarsipkan dokumen tanpa menghapus berkas maupun halamannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();

    app(TenantContext::class)->withBusiness(
        $business,
        fn () => app(DocumentProcessingService::class)->process($document)
    );

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/archive")
        ->assertRedirect()
        ->assertSessionHas('success');

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentStatus::Archived);
    expect($document->archived_at)->not->toBeNull();
    expect($document->archived_by)->toBe($owner->getKey());

    /*
     * plan.md §44.7 melarang penghapusan jejak, dan dokumen yang sudah menjadi evidence
     * journal harus tetap dapat dibuka.
     */
    Storage::disk('documents')->assertExists($document->originalFile->path);
    expect($document->pages()->withoutGlobalScopes()->count())->toBe(1);
});

it('mengeluarkan dokumen dari arsip ketika diproses ulang', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/archive")
        ->assertRedirect();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/reprocess")
        ->assertRedirect();

    $document->refresh();

    // Status ARCHIVED dan antrean proses tidak dapat berlaku bersamaan.
    expect($document->processing_status)->toBe(DocumentStatus::Queued);
    expect($document->archived_at)->toBeNull();
});

it('menolak reprocess dan archive dari staff yang tidak punya izin kelola', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    // plan.md §4.3 tidak memberi staff hak memproses ulang maupun mengarsipkan dokumen.
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/reprocess")
        ->assertForbidden();

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/archive")
        ->assertForbidden();

    // Staff tetap boleh melihat dan mengunduh dokumen sebagai evidence.
    $this->actingAs($staff)
        ->get("/businesses/{$business->getKey()}/documents/{$document->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.reprocess', false)
            ->where('can.archive', false)
            ->where('can.download', true)
        );
});

it('memaginasi inbox di sisi server', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    for ($index = 1; $index <= 27; $index++) {
        $this->ingestDocument($business, $owner, $this->csvFile("mutasi-{$index}.csv"));
    }

    // plan.md §40: paginated list.
    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents")
        ->assertInertia(fn ($page) => $page
            ->count('documents.data', 25)
            ->where('documents.meta.total', 27)
            ->where('documents.meta.last_page', 2)
        );

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}/documents?page=2")
        ->assertInertia(fn ($page) => $page->count('documents.data', 2));
});

it('menampilkan menu inbox hanya untuk user yang boleh melihat dokumen', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)
        ->get("/businesses/{$business->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'permissions',
            fn (Illuminate\Support\Collection $permissions): bool => $permissions->contains('document.view')
                && $permissions->contains('document.upload')
        ));
});

<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentFileKind;
use App\Domain\Documents\Exceptions\ImmutableDocumentFile;
use App\Domain\Documents\Models\DocumentFile;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * plan.md §37 Phase 3 acceptance: "original file tetap tersimpan".
 * plan.md §45.12: source traceability sampai file asli tidak boleh terputus.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menolak perubahan pada baris berkas asli', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $file = $document->originalFile;

    expect(fn () => $file->update(['path' => 'businesses/lain/documents/palsu.csv']))
        ->toThrow(ImmutableDocumentFile::class);

    // Baris di database tidak berubah.
    expect($document->refresh()->originalFile->path)->not->toContain('palsu');
});

it('menolak penghapusan baris berkas asli', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    expect(fn () => $document->originalFile->delete())->toThrow(ImmutableDocumentFile::class);

    expect($document->files()->withoutGlobalScopes()->count())->toBe(1);
});

it('menolak berkas asli kedua untuk dokumen yang sama', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    /*
     * Index unique parsial di database yang menegakkan aturan ini, bukan hanya kode
     * aplikasi, sehingga tidak ada jalur tulis yang bisa melewatinya.
     */
    expect(fn () => app(TenantContext::class)->withBusiness(
        $business,
        fn (): DocumentFile => $document->files()->create([
            'business_id' => $business->getKey(),
            'kind' => DocumentFileKind::Original,
            'disk' => 'documents',
            'path' => 'businesses/x/documents/lain.csv',
            'mime_type' => 'text/csv',
            'byte_size' => 10,
            'checksum_sha256' => str_repeat('a', 64),
        ])
    ))->toThrow(QueryException::class);
});

it('menolak berkas dengan ukuran nol', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    // Berkas kosong bukan dokumen; constraint database menutup seluruh jalur tulis.
    expect(fn () => app(TenantContext::class)->withBusiness(
        $business,
        fn (): DocumentFile => $document->files()->create([
            'business_id' => $business->getKey(),
            'kind' => DocumentFileKind::Thumbnail,
            'disk' => 'documents',
            'path' => 'businesses/x/documents/thumb.png',
            'mime_type' => 'image/png',
            'byte_size' => 0,
            'checksum_sha256' => str_repeat('b', 64),
        ])
    ))->toThrow(QueryException::class);
});

it('mempertahankan berkas asli setelah beberapa kali proses ulang', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $path = $document->originalFile->path;
    $contents = Storage::disk('documents')->get($path);

    $this->fakeParserSuccess();

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        $processing = app(App\Services\DocumentProcessing\DocumentProcessingService::class);

        $processing->process($document);
        $processing->reprocess($document, $owner);
        $processing->process($document->refresh());
        $processing->reprocess($document, $owner);
        $processing->process($document->refresh());
    });

    // Berkas yang sama dibaca setiap kali; isinya tidak pernah tersentuh.
    expect($document->refresh()->originalFile->path)->toBe($path);
    expect(Storage::disk('documents')->get($path))->toBe($contents);
    expect($document->files()->withoutGlobalScopes()->count())->toBe(1);
});

it('tidak menyediakan jalur hapus dokumen bagi siapa pun', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    /*
     * plan.md §44.7 melarang penghapusan jejak. Arsip adalah satu-satunya cara
     * mengeluarkan dokumen dari inbox.
     */
    expect($owner->can('delete', $document))->toBeFalse();

    $this->actingAs($owner)
        ->delete("/businesses/{$business->getKey()}/documents/{$document->getKey()}")
        ->assertStatus(405);
});

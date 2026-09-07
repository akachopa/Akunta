<?php

declare(strict_types=1);

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Documents\Enums\DocumentSourceType;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\DocumentTypeSource;
use App\Domain\Documents\Models\Document;
use App\Jobs\ProcessDocumentJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * plan.md §37 Phase 3 acceptance: multi-file upload berhasil dan original file tetap
 * tersimpan.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menerima satu berkas dan menyimpan aslinya di private storage', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $response = $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile('mutasi-januari.csv')],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $document = Document::query()->withoutGlobalScopes()->sole();

    expect($document->business_id)->toBe($business->getKey());
    expect($document->original_filename)->toBe('mutasi-januari.csv');
    expect($document->source_type)->toBe(DocumentSourceType::Upload);
    expect($document->processing_status)->toBe(DocumentStatus::Queued);
    expect($document->byte_size)->toBeGreaterThan(0);

    $file = $document->originalFile;

    expect($file)->not->toBeNull();
    Storage::disk('documents')->assertExists($file->path);

    // plan.md §30 dan §44.11: dokumen finansial tidak boleh berada di public storage.
    expect($file->path)->toStartWith("businesses/{$business->getKey()}/documents/");
    expect(Storage::disk('documents')->getVisibility($file->path))->toBe('private');
});

it('menyimpan checksum yang cocok dengan isi berkas yang tersimpan', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $contents = "Tanggal,Nominal\n2026-01-15,2000000\n";

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [UploadedFile::fake()->createWithContent('mutasi.csv', $contents)],
    ])->assertRedirect();

    $document = Document::query()->withoutGlobalScopes()->sole();

    /*
     * Checksum adalah bukti bahwa berkas yang tersimpan identik dengan yang diunggah
     * (plan.md §45.12 source traceability).
     */
    expect($document->checksum_sha256)->toBe(hash('sha256', $contents));
    expect(hash('sha256', Storage::disk('documents')->get($document->originalFile->path)))
        ->toBe($document->checksum_sha256);
});

it('menerima beberapa berkas sekaligus', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [
            $this->csvFile('mutasi.csv'),
            $this->pdfFile('invoice.pdf'),
            $this->imageFile('struk.png'),
        ],
    ])->assertRedirect()->assertSessionHas('success');

    $documents = Document::query()->withoutGlobalScopes()->get();

    expect($documents)->toHaveCount(3);
    expect($documents->pluck('original_filename')->sort()->values()->all())
        ->toBe(['invoice.pdf', 'mutasi.csv', 'struk.png']);

    // Setiap dokumen mendapat berkas aslinya sendiri.
    expect($documents->pluck('checksum_sha256')->unique())->toHaveCount(3);

    foreach ($documents as $document) {
        Storage::disk('documents')->assertExists($document->originalFile->path);
    }
});

it('memberi referensi berurutan per bisnis', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    [$otherOwner, $otherBusiness] = $this->provisionBusinessWithOwner('Toko Lain');

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile('a.csv'), $this->csvFile('b.csv')],
    ])->assertRedirect();

    $this->actingAs($otherOwner)->post("/businesses/{$otherBusiness->getKey()}/documents", [
        'files' => [$this->csvFile('c.csv')],
    ])->assertRedirect();

    $references = Document::query()
        ->withoutGlobalScopes()
        ->where('business_id', $business->getKey())
        ->orderBy('reference')
        ->pluck('reference');

    expect($references->all())->toBe(['DOC-000001', 'DOC-000002']);

    // Penomoran dimulai ulang di bisnis lain, jadi referensi tidak membocorkan volume
    // dokumen tenant lain.
    $otherReference = Document::query()
        ->withoutGlobalScopes()
        ->where('business_id', $otherBusiness->getKey())
        ->value('reference');

    expect($otherReference)->toBe('DOC-000001');
});

it('mengantre job pemrosesan tanpa memparse di dalam request', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile()],
    ])->assertRedirect();

    // plan.md §40: upload async, UI tidak menunggu proses.
    Queue::assertPushed(ProcessDocumentJob::class, 1);

    $document = Document::query()->withoutGlobalScopes()->sole();

    expect($document->processing_status)->toBe(DocumentStatus::Queued);
    expect($document->page_count)->toBeNull();
    expect($document->pages()->withoutGlobalScopes()->count())->toBe(0);

    $job = $document->processingJobs()->withoutGlobalScopes()->sole();

    expect($job->stage->value)->toBe('parse');
    expect($job->status->value)->toBe('queued');
    expect($job->attempt)->toBe(1);
});

it('menolak tipe berkas yang tidak didukung', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    // plan.md §30 mewajibkan MIME validation.
    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents", [
            'files' => [$this->unsupportedFile('kontrak.docx')],
        ])
        ->assertSessionHasErrors('files.0');

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('menolak berkas yang ekstensinya diganti agar lolos', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    /*
     * Berkas nyata dipakai di sini, bukan UploadedFile::fake(), karena fake menurunkan
     * MIME dari nama berkas sehingga penyamaran ekstensi tidak akan pernah terdeteksi.
     * Yang perlu dibuktikan justru sebaliknya: aturan `mimetypes` membaca isi berkas.
     */
    $path = tempnam(sys_get_temp_dir(), 'akunta');
    file_put_contents($path, "\x7fELF\x02\x01\x01\x00" . str_repeat('binary payload', 32));

    $disguised = new UploadedFile($path, 'invoice.pdf', null, null, true);

    expect($disguised->getMimeType())->not->toBe('application/pdf');

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents", ['files' => [$disguised]])
        ->assertSessionHasErrors('files.0');

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('menolak berkas yang melebihi batas ukuran', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    config()->set('akunta.documents.max_upload_size_kb', 10);

    // plan.md §30 mewajibkan file-size limit.
    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents", [
            'files' => [UploadedFile::fake()->create('besar.pdf', 64, 'application/pdf')],
        ])
        ->assertSessionHasErrors('files.0');

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('menolak upload tanpa berkas', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents", [])
        ->assertSessionHasErrors('files');
});

it('menerima jenis dokumen sebagai petunjuk opsional', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile()],
        'document_type' => DocumentType::BankStatement->value,
    ])->assertRedirect();

    $document = Document::query()->withoutGlobalScopes()->sole();

    expect($document->document_type)->toBe(DocumentType::BankStatement);

    // Asal nilai dicatat supaya classifier tahu ini pernyataan user, bukan tebakan model,
    // dan tidak menimpanya (plan.md §17.1).
    expect($document->document_type_source)->toBe(DocumentTypeSource::User);
    expect($document->hasHumanDocumentType())->toBeTrue();
});

it('membiarkan jenis dokumen kosong ketika user tidak menentukannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    // plan.md §5.2: user tidak wajib menentukan jenis dokumen.
    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [$this->csvFile()],
    ])->assertRedirect();

    $document = Document::query()->withoutGlobalScopes()->sole();

    expect($document->document_type)->toBeNull();
    expect($document->document_type_source)->toBeNull();
});

it('membuang komponen path dari nama berkas asli', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $path = tempnam(sys_get_temp_dir(), 'akunta');
    file_put_contents($path, "Tanggal,Nominal\n2026-01-15,1000\n");

    $this->actingAs($owner)->post("/businesses/{$business->getKey()}/documents", [
        'files' => [new UploadedFile($path, '../../etc/passwd.csv', 'text/csv', null, true)],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $document = Document::query()->withoutGlobalScopes()->sole();

    expect($document->original_filename)->toBe('passwd.csv');

    // Nama berkas dari user tidak pernah dipakai sebagai key storage.
    expect($document->originalFile->path)->not->toContain('passwd');
});

it('menolak upload dari role yang tidak punya izin unggah', function (): void {
    [, $business] = $this->provisionBusinessWithOwner();

    /*
     * plan.md §4.4 tidak memberi accountant izin apa pun untuk mengelola member, tetapi
     * memberinya akses dokumen. Yang diuji di sini adalah user yang sama sekali bukan
     * member: ia harus mendapat 404, bukan 403 (plan.md §44.15).
     */
    $outsider = App\Models\User::factory()->create();

    $this->actingAs($outsider)
        ->post("/businesses/{$business->getKey()}/documents", ['files' => [$this->csvFile()]])
        ->assertNotFound();

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('mengizinkan owner, staff, dan accountant mengunggah dokumen', function (RoleSlug $role): void {
    [, $business] = $this->provisionBusinessWithOwner();

    $member = $this->addMember($business, $role);

    $this->actingAs($member)
        ->post("/businesses/{$business->getKey()}/documents", ['files' => [$this->csvFile()]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(1);
})->with([
    // plan.md §4.2 dan §4.3 memberi owner dan staff hak upload; §4.4 accountant
    // membutuhkannya untuk rekonsiliasi.
    'staff' => RoleSlug::BusinessStaff,
    'accountant' => RoleSlug::Accountant,
]);

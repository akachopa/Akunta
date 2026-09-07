<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentPageKind;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\ProcessingJobStatus;
use App\Domain\Documents\Exceptions\DocumentParsingFailed;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ClassifyDocumentJob;
use App\Jobs\ProcessDocumentJob;
use App\Services\DocumentProcessing\DocumentProcessingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * plan.md §13.1 PARSER ROUTER dan §37 Phase 3: status pemrosesan serta retry.
 *
 * Respons AI worker di-fake; parser aslinya diuji oleh pytest di ai-worker.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

/**
 * Menjalankan tahap parse untuk dokumen tertentu di dalam tenant context bisnisnya,
 * meniru apa yang dilakukan ProcessDocumentJob di queue worker.
 */
function runParse(Document $document): bool
{
    return app(TenantContext::class)->withBusiness(
        $document->business,
        fn (): bool => app(DocumentProcessingService::class)->process($document)
    );
}

it('memparse dokumen dan menyimpan halamannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();

    expect(runParse($document))->toBeTrue();

    $document->refresh();

    expect($document->content_kind)->toBe('table');
    expect($document->page_count)->toBe(1);
    expect($document->needs_ocr)->toBeFalse();
    expect($document->parsed_at)->not->toBeNull();

    $page = $document->pages()->withoutGlobalScopes()->sole();

    expect($page->kind)->toBe(DocumentPageKind::Table);
    expect($page->row_count)->toBe(2);
    expect($page->rows[1])->toBe(['2026-01-15', 'SETORAN TUNAI', '2000000']);
});

it('menyerahkan dokumen ke tahap klasifikasi setelah parse', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    runParse($document);

    /*
     * Menandai dokumen READY di sini akan menyesatkan: dokumennya baru terbaca dan belum
     * satu pun datanya dibaca (plan.md §25.1).
     */
    expect($document->refresh()->processing_status)->toBe(DocumentStatus::Classifying);
});

it('mencatat tahap phase berikutnya sebagai pending agar batas phase terlihat', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    runParse($document);

    $jobs = $document->processingJobs()->withoutGlobalScopes()->get()->keyBy('stage.value');

    expect($jobs['parse']->status)->toBe(ProcessingJobStatus::Succeeded);
    expect($jobs['parse']->result['parser'])->toBe('csv');
    expect($jobs['parse']->result['parser_version'])->toBe('1.0');
    expect($jobs['parse']->duration_ms)->not->toBeNull();

    /*
     * Classify, extract, dan normalize tidak muncul sebagai baris menunggu: ketiganya sudah
     * dibangun, dan job-nya dibuat ketika tahapnya benar-benar dijalankan.
     */
    expect($jobs->has('classify'))->toBeFalse();
    expect($jobs->has('extract'))->toBeFalse();
    expect($jobs->has('normalize'))->toBeFalse();

    expect($jobs['match']->status)->toBe(ProcessingJobStatus::Pending);
    expect($jobs['match']->error_message)->toContain('Phase');
});

it('mengirim job klasifikasi setelah parse berhasil', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();

    // Rantai tahap berada di lapisan job, bukan di dalam service (plan.md §13.1).
    app(TenantContext::class)->withBusiness(
        $business,
        fn () => (new ProcessDocumentJob($document->getKey(), $business->getKey()))
            ->handle(app(TenantContext::class))
    );

    Queue::assertPushed(ClassifyDocumentJob::class);
});

it('mengirim berkas ke worker sebagai multipart tanpa membocorkan kredensial storage', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    runParse($document);

    // plan.md §30: worker tidak boleh memerlukan akses object storage.
    Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($document): bool {
        return str_ends_with($request->url(), '/v1/parse')
            && $request->hasHeader('Authorization', 'Bearer testing-token')
            && $request->isMultipart()
            && collect($request->data())->contains(
                fn (array $part): bool => ($part['name'] ?? '') === 'filename'
                    && ($part['contents'] ?? '') === $document->original_filename
            );
    });
});

it('menandai dokumen unsupported ketika worker tidak punya parser', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner, $this->pdfFile());

    $this->fakeParserUnsupported();

    // Unsupported bukan kegagalan sistem, jadi tidak melempar exception dan tidak diretry.
    expect(runParse($document))->toBeTrue();

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentStatus::Unsupported);
    expect($document->failure_reason)->toContain('parser');

    $job = $document->processingJobs()->withoutGlobalScopes()->forStage(
        App\Domain\Documents\Enums\DocumentProcessingStage::Parse
    )->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Skipped);
});

it('mencatat percobaan gagal dan melempar ulang agar queue mencoba lagi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserUnreachable();

    expect(fn (): bool => runParse($document))->toThrow(DocumentParsingFailed::class);

    $document->refresh();

    /*
     * Dokumen dibiarkan PARSING selama masih ada percobaan otomatis tersisa: user tidak
     * boleh melihatnya ditandai gagal padahal akan dicoba lagi.
     */
    expect($document->processing_status)->toBe(DocumentStatus::Parsing);

    $job = $document->processingJobs()->withoutGlobalScopes()->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Failed);
    expect($job->error_message)->toContain('AI worker tidak dapat dihubungi');
});

it('menandai dokumen gagal setelah seluruh percobaan queue habis', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserUnreachable();

    try {
        runParse($document);
    } catch (DocumentParsingFailed) {
        // Percobaan pertama memang gagal; yang diuji adalah penutupannya oleh queue.
    }

    // Meniru pemanggilan failed() oleh queue setelah tries habis.
    (new ProcessDocumentJob($document->getKey(), $business->getKey()))
        ->failed(DocumentParsingFailed::workerUnreachable('Connection refused'));

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentStatus::Failed);
    expect($document->failed_at)->not->toBeNull();
    expect($document->failure_reason)->toContain('AI worker tidak dapat dihubungi');
    expect($document->processing_status->isRetryable())->toBeTrue();
});

it('memproses ulang dokumen gagal dengan berkas asli yang sama', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $originalPath = $document->originalFile->path;
    $originalChecksum = $document->checksum_sha256;

    $this->fakeParserUnreachable();

    try {
        runParse($document);
    } catch (DocumentParsingFailed) {
        // diabaikan
    }

    (new ProcessDocumentJob($document->getKey(), $business->getKey()))
        ->failed(DocumentParsingFailed::workerUnreachable('Connection refused'));

    // plan.md §37 Phase 3 acceptance: "failure dapat diretry".
    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        app(DocumentProcessingService::class)->reprocess($document, $owner);
    });

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentStatus::Queued);
    expect($document->failure_reason)->toBeNull();
    expect($document->failed_at)->toBeNull();

    $this->fakeParserSuccess();

    expect(runParse($document))->toBeTrue();

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentStatus::Classifying);

    // Retry membaca berkas yang sama; berkas asli tidak pernah diganti.
    expect($document->originalFile->path)->toBe($originalPath);
    expect($document->checksum_sha256)->toBe($originalChecksum);
    Storage::disk('documents')->assertExists($originalPath);

    $attempts = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(App\Domain\Documents\Enums\DocumentProcessingStage::Parse)
        ->orderBy('attempt')
        ->get();

    // Riwayat percobaan tidak ditimpa (plan.md §32.1).
    expect($attempts->pluck('attempt')->all())->toBe([1, 2]);
    expect($attempts->first()->status)->toBe(ProcessingJobStatus::Failed);
    expect($attempts->last()->status)->toBe(ProcessingJobStatus::Succeeded);
});

it('tidak menduplikasi halaman ketika dokumen diproses ulang', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    runParse($document);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        app(DocumentProcessingService::class)->reprocess($document, $owner);
    });

    runParse($document->refresh());

    // Halaman adalah data turunan dan ditulis ulang setiap percobaan.
    expect($document->pages()->withoutGlobalScopes()->count())->toBe(1);
});

it('melewati dokumen yang statusnya tidak menunggu proses', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    runParse($document);

    // plan.md §40: job harus idempotent. Job yang terkirim dua kali tidak boleh memparse ulang.
    expect(runParse($document->refresh()))->toBeFalse();

    Http::assertSentCount(1);
    expect($document->pages()->withoutGlobalScopes()->count())->toBe(1);
});

it('menandai halaman gambar sebagai menunggu ocr', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner, $this->imageFile());

    $this->fakeParserSuccess([
        [
            'page_number' => 1,
            'kind' => 'image',
            'label' => 'struk.png',
            'text' => null,
            'rows' => null,
            'needs_ocr' => true,
            'metadata' => ['width' => 60, 'height' => 40],
        ],
    ], contentKind: 'image');

    runParse($document);

    $document->refresh();

    /*
     * OCR gambar memerlukan vision model (plan.md §13.2), jadi Phase 3 hanya menandainya
     * dan tidak menebak isi struk.
     */
    expect($document->needs_ocr)->toBeTrue();
    expect($document->pages()->withoutGlobalScopes()->sole()->text)->toBeNull();
    expect($document->processing_status)->toBe(DocumentStatus::Classifying);
});

it('menandai gagal ketika berkas asli hilang dari storage', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    Storage::disk('documents')->delete($document->originalFile->path);

    expect(fn (): bool => runParse($document))->toThrow(DocumentParsingFailed::class);

    // Worker tidak pernah dihubungi karena tidak ada yang bisa dikirim.
    Http::assertNothingSent();
});

it('menjalankan job dengan tenant context bisnis dokumennya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();

    /*
     * Job memuat dokumen tanpa tenant aktif dari luar, sehingga global scope tenant harus
     * dipasang oleh job itu sendiri (plan.md §44.15).
     */
    app(TenantContext::class)->forget();

    (new ProcessDocumentJob($document->getKey(), $business->getKey()))
        ->handle(app(TenantContext::class));

    expect($document->refresh()->processing_status)->toBe(DocumentStatus::Classifying);
});

it('memakai id dokumen sebagai kunci unik job agar tidak diproses dua kali', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    // plan.md §40: duplicate job protection.
    $job = new ProcessDocumentJob($document->getKey(), $business->getKey());

    expect($job->uniqueId())->toBe($document->getKey());
    expect($job->tries)->toBeGreaterThan(1);
    expect($job->backoff)->not->toBeEmpty();
});

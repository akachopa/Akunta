<?php

declare(strict_types=1);

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiFeedback;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentFieldSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\DocumentTypeSource;
use App\Domain\Documents\Exceptions\DocumentReviewRejected;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ExtractDocumentJob;
use App\Jobs\NormalizeDocumentJob;
use App\Models\User;
use App\Services\DocumentProcessing\DocumentReviewService;
use Illuminate\Support\Facades\Queue;

/**
 * plan.md §16, §17.1, dan §37 Phase 4 "review UI untuk extracted fields".
 *
 * Yang diuji di sini adalah akibat review terhadap data: nilai yang berlaku, confidence-nya,
 * jejak koreksinya pada `ai_feedback`, dan batas siapa yang boleh menyatakannya.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

/**
 * Dokumen yang sudah melewati pipeline dan menunggu review karena confidence-nya di bawah
 * ambang auto-ready.
 */
function documentAwaitingReview(
    Business $business,
    User $owner,
    float $confidence = 0.84,
    float $classifyConfidence = 0.93,
): Document {
    /** @var Tests\TestCase $test */
    $test = test();

    $document = $test->ingestDocument($business, $owner);

    $test->fakeParserSuccess();
    $test->fakeClassifierSuccess('purchase_invoice', $classifyConfidence);
    $test->fakeExtractorResponse($test->invoiceFields(confidence: $confidence), confidence: $confidence);

    return $test->runIntelligencePipeline($document);
}

function review(): DocumentReviewService
{
    return app(DocumentReviewService::class);
}

it('menetapkan jenis dokumen pilihan reviewer dan membaca ulang datanya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        review()->confirmDocumentType($document, DocumentType::Receipt, $owner, 'Struk kasir, bukan faktur.');
    });

    $document->refresh();

    expect($document->document_type)->toBe(DocumentType::Receipt);
    expect($document->document_type_source)->toBe(DocumentTypeSource::Review);

    // Jenis dokumen yang dinyatakan manusia tidak lagi membawa ketidakpastian.
    expect($document->classification_confidence)->toBe('1.0000');

    /*
     * Schema ekstraksinya berubah, sehingga dokumen harus dibaca ulang sebelum datanya
     * boleh dipakai (plan.md §17.1).
     */
    expect($document->processing_status)->toBe(DocumentStatus::Extracting);
    Queue::assertPushed(ExtractDocumentJob::class);

    // plan.md §31: prediksi tidak dihapus, hanya dikalahkan.
    $prediction = $document->predictions()->sole();

    expect($prediction->predicted_value)->toBe('purchase_invoice');
    expect($prediction->status)->toBe(AiPredictionStatus::Superseded);

    // plan.md §17.1: koreksi tersimpan sebagai bahan pengukuran akurasi.
    $feedback = AiFeedback::query()->where('task', AiTask::ClassifyDocument->value)->sole();

    expect($feedback->original_value)->toBe('purchase_invoice');
    expect($feedback->original_confidence)->toBe('0.9300');
    expect($feedback->final_value)->toBe('receipt');
    expect($feedback->reviewer_id)->toBe($owner->getKey());
    expect($feedback->reason)->toBe('Struk kasir, bukan faktur.');
});

it('tidak membaca ulang dokumen ketika reviewer menyetujui jenis yang sudah diprediksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        review()->confirmDocumentType($document, DocumentType::PurchaseInvoice, $owner);
    });

    $document->refresh();

    expect($document->document_type_source)->toBe(DocumentTypeSource::Review);
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);

    // Konfirmasi bukan koreksi: tidak ada nilai yang berubah, jadi tidak ada feedback.
    Queue::assertNotPushed(ExtractDocumentJob::class);
    expect(AiFeedback::query()->count())->toBe(0);
});

it('menetapkan nilai koreksi reviewer beserta kepastiannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        review()->confirmFields($document, [
            'subtotal' => '1200000.00',
            'tax' => '132000.00',
            'total' => '1332000.00',
        ], $owner, 'Angka pada faktur terbaca kurang satu digit.');
    });

    $document->refresh();

    $fields = $document->fields()->documentLevel()->get()->keyBy('field_key.value');
    $total = $fields['total'];

    expect($total->value_number)->toBe('1332000.00');
    expect($total->confidence)->toBe('1.0000');
    expect($total->is_confirmed)->toBeTrue();
    expect($total->confirmed_by)->toBe($owner->getKey());
    expect($total->source)->toBe(DocumentFieldSource::Review);

    // plan.md §45.12: bukti asal nilai tidak dihapus meski nilainya dikoreksi.
    expect($total->page_number)->toBe(1);
    expect($total->source_text)->toContain('1110000.00');

    // Proyeksi canonical mengikuti nilai yang berlaku, bukan nilai yang dikoreksi.
    expect($document->total)->toBe('1332000.00');
    expect($document->subtotal)->toBe('1200000.00');

    $feedback = AiFeedback::query()->where('field_key', 'total')->sole();

    expect($feedback->original_value)->toBe('1110000.00');
    expect($feedback->final_value)->toBe('1332000.00');
    expect($feedback->original_confidence)->toBe('0.8400');
});

it('menandai field terkonfirmasi tanpa mencatatnya sebagai kesalahan AI', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        review()->confirmFields($document, ['total' => '1110000.00'], $owner);
    });

    $total = $document->fields()->documentLevel()->where('field_key', 'total')->sole();

    expect($total->is_confirmed)->toBeTrue();
    expect($total->confidence)->toBe('1.0000');

    /*
     * "AI benar" dan "AI salah lalu dibetulkan" tidak boleh tercampur pada pengukuran
     * akurasi (plan.md §32.2).
     */
    expect($total->source)->toBe(DocumentFieldSource::Ai);
    expect(AiFeedback::query()->count())->toBe(0);
});

it('menahan dokumen di antrean review selama masih ada field yang belum diperiksa', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        review()->confirmFields($document, ['total' => '1110000.00'], $owner);
    });

    $document->refresh();

    /*
     * Aturan mata rantai terlemah plan.md §15.1: satu field pasti tidak membuat seluruh
     * dokumen pasti.
     */
    expect($document->extraction_confidence)->toBe('0.8400');
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
});

it('menandai dokumen siap ketika seluruh field sudah dipastikan reviewer', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    // Jenis dokumennya sudah yakin; yang menahan dokumen hanyalah angka-angkanya.
    $document = documentAwaitingReview($business, $owner, classifyConfidence: 0.97);

    $keys = $document->fields()->documentLevel()->get()
        ->mapWithKeys(fn ($field): array => [$field->field_key->value => $field->displayValue()])
        ->all();

    app(TenantContext::class)->withBusiness($business, function () use ($document, $keys, $owner): void {
        review()->confirmFields($document, $keys, $owner);
    });

    $document->refresh();

    /*
     * Koreksi yang membuat dokumen melewati ambang tidak berhenti di READY: nilai yang
     * berlaku sudah berubah, dan transaksinya harus dibentuk dari nilai itu (plan.md §37
     * Phase 5).
     */
    expect($document->extraction_confidence)->toBe('1.0000');
    expect($document->processing_status)->toBe(DocumentStatus::Normalizing);
    Queue::assertPushed(NormalizeDocumentJob::class);

    $document = $this->runNormalization($document);

    expect($document->processing_status)->toBe(DocumentStatus::Ready);
    expect($document->review_reason)->toBeNull();
    expect(Document::query()->withoutGlobalScopes()->readyForTransactionPipeline()->count())->toBe(1);
});

it('tetap menahan dokumen ketika datanya pasti tetapi jenisnya belum', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner, classifyConfidence: 0.71);

    $keys = $document->fields()->documentLevel()->get()
        ->mapWithKeys(fn ($field): array => [$field->field_key->value => $field->displayValue()])
        ->all();

    app(TenantContext::class)->withBusiness($business, function () use ($document, $keys, $owner): void {
        review()->confirmFields($document, $keys, $owner);
    });

    $document->refresh();

    /*
     * Komponen confidence tidak saling menutupi (plan.md §15.1): angka yang sudah pasti
     * tidak membuat dokumen yang jenisnya masih kabur layak diproses otomatis.
     */
    expect($document->extraction_confidence)->toBe('1.0000');
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->review_reason)->toContain('jenis dokumennya belum dapat dipastikan');
});

it('menolak koreksi yang nilainya tidak dapat dibaca', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $correct = fn (): Document => app(TenantContext::class)->withBusiness(
        $business,
        fn (): Document => review()->confirmFields($document, ['document_date' => '2026-02-30'], $owner)
    );

    // Koreksi manusia melewati validator yang sama dengan output model.
    expect($correct)->toThrow(DocumentReviewRejected::class);

    expect($document->fields()->documentLevel()->where('field_key', 'document_date')->sole()->value_date->toDateString())
        ->toBe('2026-02-10');
});

it('menolak koreksi atas field yang tidak pernah diekstraksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $correct = fn (): Document => app(TenantContext::class)->withBusiness(
        $business,
        fn (): Document => review()->confirmFields($document, ['opening_balance' => '5000000.00'], $owner)
    );

    expect($correct)->toThrow(DocumentReviewRejected::class);
});

it('menolak koreksi baris mutasi lewat formulir field dokumen', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    // Baris mutasi dikoreksi pada tahap rekonsiliasi Phase 7, bukan di sini.
    $correct = fn (): Document => app(TenantContext::class)->withBusiness(
        $business,
        fn (): Document => review()->confirmFields($document, ['debit' => '500000.00'], $owner)
    );

    expect($correct)->toThrow(DocumentReviewRejected::class);
});

it('menyetujui dokumen atas pernyataan reviewer', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    app(TenantContext::class)->withBusiness($business, function () use ($document, $owner): void {
        review()->approve($document, $owner, 'Sudah dicocokkan dengan faktur fisik.');
    });

    $document->refresh();

    /*
     * plan.md §16.1: persetujuan manusia adalah satu-satunya jalan dokumen melewati ambang
     * confidence tanpa memenuhinya. Yang disetujui adalah datanya, sehingga dokumen masuk
     * ke normalisasi dan baru mencapai READY setelah transaksinya terbentuk.
     */
    expect($document->processing_status)->toBe(DocumentStatus::Normalizing);
    expect($document->reviewed_by)->toBe($owner->getKey());
    expect($document->review_reason)->toBeNull();
    Queue::assertPushed(NormalizeDocumentJob::class);

    expect($this->runNormalization($document)->processing_status)->toBe(DocumentStatus::Ready);

    // plan.md §31: keputusan manusia atas data akuntansi harus terekam.
    $log = AuditLog::query()->where('action', 'document.review_approved')->sole();

    expect($log->reason)->toBe('Sudah dicocokkan dengan faktur fisik.');
    expect($log->actor_id)->toBe($owner->getKey());
});

it('menolak persetujuan dokumen yang tidak punya ekstraksi lolos validasi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(total: '1200000.00'));

    $document = $this->runIntelligencePipeline($document);

    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);

    // Persetujuan manusia tidak dapat menciptakan data yang tidak pernah terbaca.
    $approve = fn (): Document => app(TenantContext::class)->withBusiness(
        $business,
        fn (): Document => review()->approve($document, $owner)
    );

    expect($approve)->toThrow(DocumentReviewRejected::class);
    expect($document->refresh()->processing_status)->toBe(DocumentStatus::NeedReview);
});

it('menolak review dokumen yang masih berjalan di pipeline', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess();

    app(TenantContext::class)->withBusiness($business, function () use ($document): void {
        app(App\Services\DocumentProcessing\DocumentProcessingService::class)->process($document);
        app(App\Services\DocumentProcessing\DocumentClassificationService::class)->classify($document);
    });

    expect($document->refresh()->processing_status)->toBe(DocumentStatus::Extracting);

    /*
     * Tahap yang sedang berjalan akan menulis ulang field dan status dokumen, sehingga
     * koreksi yang masuk di tengahnya hilang tanpa jejak.
     */
    $approve = fn (): Document => app(TenantContext::class)->withBusiness(
        $business,
        fn (): Document => review()->approve($document, $owner)
    );

    expect($approve)->toThrow(DocumentReviewRejected::class);
});

it('mengoreksi field lewat endpoint review', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/fields", [
            'fields' => ['total' => '1332000.00', 'subtotal' => '1200000.00', 'tax' => '132000.00'],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($document->refresh()->total)->toBe('1332000.00');
});

it('menolak nilai uang berpemisah ribuan pada endpoint review', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/fields", [
            'fields' => ['total' => '1.332.000'],
        ])
        ->assertSessionHasErrors('fields.total');

    expect($document->refresh()->total)->toBe('1110000.00');
});

it('menolak jenis dokumen di luar taksonomi pada endpoint review', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/type", [
            'document_type' => 'invoice_tebakan',
        ])
        ->assertSessionHasErrors('document_type');

    // `unknown` bukan pilihan reviewer: ia adalah hasil, bukan pernyataan.
    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/type", [
            'document_type' => DocumentType::Unknown->value,
        ])
        ->assertSessionHasErrors('document_type');
});

it('menolak review oleh anggota tanpa permission review', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    // plan.md §4.3: staff hanya menginput dan melihat; koreksi field adalah pernyataan.
    $staff = $this->addMember($business, RoleSlug::BusinessStaff);

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/approve")
        ->assertForbidden();

    $this->actingAs($staff)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/fields", [
            'fields' => ['total' => '9999.00'],
        ])
        ->assertForbidden();

    expect($document->refresh()->processing_status)->toBe(DocumentStatus::NeedReview);
});

it('mengizinkan accountant client mereview dokumen bisnis yang menugaskannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    // plan.md §4.4: accountant melakukan review dan koreksi.
    $accountant = $this->addMember($business, RoleSlug::Accountant, true);

    $this->actingAs($accountant)
        ->post("/businesses/{$business->getKey()}/documents/{$document->getKey()}/review/approve")
        ->assertRedirect();

    expect($document->refresh()->processing_status)->toBe(DocumentStatus::Normalizing);
    expect($this->runNormalization($document)->processing_status)->toBe(DocumentStatus::Ready);
});

it('menyembunyikan dokumen bisnis lain dari endpoint review', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    [$intruderOwner, $other] = $this->provisionBusinessWithOwner('Toko Lain');

    // plan.md §44.15: kebocoran lintas tenant tampak sebagai 404, bukan 403.
    $this->actingAs($intruderOwner)
        ->post("/businesses/{$other->getKey()}/documents/{$document->getKey()}/review/approve")
        ->assertNotFound();

    expect($document->refresh()->processing_status)->toBe(DocumentStatus::NeedReview);
});

it('mengembalikan detail field dan riwayat ekstraksi lewat api', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $this->actingAs($owner)
        ->getJson("/api/v1/documents/{$document->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.processing_status', 'need_review')
        ->assertJsonPath('data.total', '1110000.00')
        ->assertJsonPath('data.confidence.band', 'review_recommended')
        ->assertJsonPath('data.classification.predicted_value', 'purchase_invoice')
        ->assertJsonCount(7, 'data.fields')
        ->assertJsonPath('data.fields.0.key', 'document_number')
        ->assertJsonPath('data.extractions.0.status', 'accepted');
});

it('menyetujui dokumen lewat api', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = documentAwaitingReview($business, $owner);

    $this->actingAs($owner)
        ->postJson("/api/v1/documents/{$document->getKey()}/review/approve")
        ->assertOk()
        ->assertJsonPath('data.processing_status', 'normalizing');
});

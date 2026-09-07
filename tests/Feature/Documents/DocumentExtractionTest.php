<?php

declare(strict_types=1);

use App\Domain\Ai\Enums\AiRunStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiModelRun;
use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentFieldSource;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Enums\ProcessingJobStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentField;
use Illuminate\Support\Facades\Queue;

/**
 * plan.md §14.2 dan §37 Phase 4 acceptance:
 *
 * - output terstruktur tersimpan;
 * - confidence tersimpan;
 * - raw dan parsed evidence tersedia;
 * - output invalid tidak masuk transaction pipeline.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menyimpan field terstruktur beserta bukti asalnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.96), confidence: 0.96);

    $document = $this->runIntelligencePipeline($document);

    $extraction = $document->extractions()->sole();

    expect($extraction->status)->toBe(ExtractionStatus::Accepted);
    expect($extraction->extractor)->toBe('invoice');
    expect($extraction->schema_version)->toBe('1.0');
    expect($extraction->confidence)->toBe('0.9600');
    expect($extraction->attempt)->toBe(1);

    // plan.md §37 Phase 4: raw output provider tetap tersimpan berdampingan hasil olahannya.
    expect($extraction->raw_output)->toHaveKeys(['provider_output', 'worker_valid']);
    expect($extraction->raw_output['worker_valid'])->toBeTrue();

    $fields = $document->fields()->documentLevel()->get()->keyBy('field_key.value');

    expect($fields)->toHaveCount(7);

    $total = $fields['total'];

    expect($total->value_number)->toBe('1110000.00');
    expect($total->confidence)->toBe('0.9600');
    expect($total->source)->toBe(DocumentFieldSource::Ai);
    expect($total->is_confirmed)->toBeFalse();

    // plan.md §45.10, §45.12: setiap nilai membawa halaman dan cuplikan sumbernya.
    expect($total->page_number)->toBe(1);
    expect($total->source_text)->toContain('1110000.00');

    expect($fields['document_date']->value_date->toDateString())->toBe('2026-02-10');
    expect($fields['issuer_name']->value_text)->toBe('PT Sumber Kertas');
});

it('memproyeksikan nilai canonical ke dokumen dan menandainya siap', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.96), confidence: 0.96);

    $document = $this->runIntelligencePipeline($document);

    // plan.md §8.1: proyeksi canonical yang dibaca inbox dan phase berikutnya.
    expect($document->document_date->toDateString())->toBe('2026-02-10');
    expect($document->currency)->toBe('IDR');
    expect($document->subtotal)->toBe('1000000.00');
    expect($document->tax)->toBe('110000.00');
    expect($document->total)->toBe('1110000.00');

    expect($document->extraction_confidence)->toBe('0.9600');
    expect($document->extracted_at)->not->toBeNull();

    /*
     * Confidence 0,96 melewati ambang auto-ready 0,95, jadi dokumen tidak perlu
     * menunggu manusia (plan.md §15.2).
     */
    expect($document->processing_status)->toBe(DocumentStatus::Ready);
    expect($document->review_reason)->toBeNull();

    expect(Document::query()->withoutGlobalScopes()->readyForTransactionPipeline()->count())->toBe(1);
});

it('mengantre dokumen ke review ketika confidence di bawah ambang auto ready', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.93);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.84), confidence: 0.84);

    $document = $this->runIntelligencePipeline($document);

    /*
     * Ekstraksinya diterima dan field-nya tersimpan; yang menahan dokumen hanyalah
     * confidence (plan.md §15.2, §44.8).
     */
    expect($document->extractions()->sole()->status)->toBe(ExtractionStatus::Accepted);
    expect($document->fields()->documentLevel()->count())->toBe(7);

    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->review_reason)->toContain('belum terbaca dengan yakin');

    // plan.md §37 Phase 4: dokumen yang belum pasti tidak masuk transaction pipeline.
    expect(Document::query()->withoutGlobalScopes()->readyForTransactionPipeline()->count())->toBe(0);
});

it('memakai ambang confidence yang diatur bisnis', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    // plan.md §15.2: ambang dapat diatur per bisnis.
    $business->profile->update([
        'ai_auto_ready_threshold' => '0.8000',
        'ai_review_threshold' => '0.6000',
    ]);

    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.90);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.84), confidence: 0.84);

    $document = $this->runIntelligencePipeline($document);

    /*
     * Confidence 0,84 tertahan pada ambang bawaan 0,95, tetapi lolos pada ambang bisnis
     * ini. Yang berubah hanyalah ambangnya, bukan cara skornya dihitung.
     */
    expect($document->processing_status)->toBe(DocumentStatus::Ready);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Extract)
        ->sole();

    expect($job->result['confidence']['auto_ready_threshold'])->toBe('0.8000');
});

it('menolak hasil ekstraksi yang ditandai tidak valid oleh worker', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse(
        $this->invoiceFields(),
        valid: false,
        validationErrors: ['Nomor faktur tidak terbaca pada dokumen.'],
    );

    $document = $this->runIntelligencePipeline($document);

    $extraction = $document->extractions()->sole();

    expect($extraction->status)->toBe(ExtractionStatus::Rejected);
    expect($extraction->validation_errors)->toContain('Nomor faktur tidak terbaca pada dokumen.');

    // Bukti mentahnya tetap disimpan meski hasilnya ditolak.
    expect($extraction->raw_output['provider_output'])->toHaveKey('fields');

    // Acceptance Phase 4: tidak satu pun nilai yang dapat dibaca sebagai data sah.
    expect(DocumentField::query()->withoutGlobalScopes()->count())->toBe(0);
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->total)->toBeNull();

    $run = AiModelRun::query()->where('task', AiTask::ExtractDocument->value)->sole();

    expect($run->status)->toBe(AiRunStatus::Rejected);
});

it('menolak hasil yang aritmetikanya tidak konsisten walau worker menyatakannya valid', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);

    // subtotal + pajak = 1.110.000, tetapi total tertulis 1.200.000.
    $this->fakeExtractorResponse(
        $this->invoiceFields(total: '1200000.00'),
        confidence: 0.99,
    );

    $document = $this->runIntelligencePipeline($document);

    /*
     * plan.md §44.2: verdict worker bukan izin masuk. Laravel menghitung ulang identitas
     * dokumen dengan bcmath atas nilai yang akan benar-benar disimpan.
     */
    $extraction = $document->extractions()->sole();

    expect($extraction->status)->toBe(ExtractionStatus::Rejected);
    expect($extraction->validation_errors[0])->toContain('Total tidak konsisten');
    expect($extraction->raw_output['worker_valid'])->toBeTrue();

    expect($document->fields()->count())->toBe(0);
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->review_reason)->toContain('belum dapat dipastikan');
});

it('menolak nilai uang yang masih memuat pemisah ribuan atau simbol mata uang', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse([
        $this->extractedField('document_date', '2026-02-10'),
        $this->extractedField('total', 'Rp1.110.000'),
    ]);

    $document = $this->runIntelligencePipeline($document);

    // Membersihkannya berarti menebak nilai uang, dan itu dilarang plan.md §44.2.
    expect($document->extractions()->sole()->validation_errors[0])->toContain('tidak dapat dibaca sebagai');
    expect($document->total)->toBeNull();
});

it('menolak tanggal yang tidak pernah ada alih-alih membetulkannya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse([
        $this->extractedField('document_date', '2026-02-30'),
        $this->extractedField('total', '1110000.00'),
    ]);

    $document = $this->runIntelligencePipeline($document);

    /*
     * Parser tanggal akan mengubah 30 Februari menjadi 2 Maret, dan tanggal yang
     * "dibetulkan" masuk ke periode akuntansi yang salah tanpa jejak.
     */
    expect($document->extractions()->sole()->status)->toBe(ExtractionStatus::Rejected);
    expect($document->document_date)->toBeNull();
});

it('menolak ekstraksi yang kehilangan field canonical wajib', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse([
        $this->extractedField('document_number', 'INV/2026/0012'),
        $this->extractedField('issuer_name', 'PT Sumber Kertas'),
    ]);

    $document = $this->runIntelligencePipeline($document);

    $errors = $document->extractions()->sole()->validation_errors;

    // Tanpa tanggal dan nilai dokumen, ekstraksinya tidak dapat menghasilkan transaksi apa pun.
    expect(implode(' ', $errors))->toContain('Tanggal Dokumen tidak terbaca');
    expect(implode(' ', $errors))->toContain('Total tidak terbaca');
});

it('membuang kunci field di luar taksonomi tanpa menggagalkan ekstraksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse(array_merge($this->invoiceFields(confidence: 0.96), [
        $this->extractedField('estimated_profit_margin', '0.32'),
    ]), confidence: 0.96);

    $document = $this->runIntelligencePipeline($document);

    /*
     * Field karangan tidak boleh tersimpan sebagai data akuntansi: tidak ada satu pun
     * tempat yang dapat memeriksa artinya.
     */
    expect($document->processing_status)->toBe(DocumentStatus::Ready);
    expect($document->fields()->documentLevel()->pluck('field_key')->map->value->all())
        ->not->toContain('estimated_profit_margin');
});

it('menyimpan baris mutasi rekening koran dan memeriksa identitas saldonya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('period_end', '2026-01-31', 0.96),
            $this->extractedField('opening_balance', '5000000.00', 0.96),
            $this->extractedField('closing_balance', '6500000.00', 0.96),
        ],
        rows: [
            [
                'date' => '2026-01-15',
                'description' => 'SETORAN TUNAI',
                'debit' => null,
                'credit' => '2000000.00',
                'balance' => '7000000.00',
                'page_number' => 1,
                'source_text' => '15/01 SETORAN TUNAI 2.000.000',
            ],
            [
                'date' => '2026-01-20',
                'description' => 'BIAYA ADMIN',
                'debit' => '500000.00',
                'credit' => null,
                'balance' => '6500000.00',
                'page_number' => 1,
                'source_text' => '20/01 BIAYA ADMIN 500.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'bank_statement',
    );

    $document = $this->runIntelligencePipeline($document);

    expect($document->document_type)->toBe(DocumentType::BankStatement);
    expect($document->extractions()->sole()->status)->toBe(ExtractionStatus::Accepted);

    // plan.md §19.1: saldo awal + kredit − debit = saldo akhir.
    expect($document->processing_status)->toBe(DocumentStatus::Ready);

    // plan.md §8.4 row_reference: baris disimpan terurut dan terpisah dari field dokumen.
    $rows = $document->fields()->rows()->get()->groupBy('row_index');

    expect($rows)->toHaveCount(2);
    expect($rows[0]->firstWhere('field_key', DocumentFieldKey::RowCredit)->value_number)->toBe('2000000.00');
    expect($rows[1]->firstWhere('field_key', DocumentFieldKey::RowDebit)->value_number)->toBe('500000.00');
    expect($rows[0]->firstWhere('field_key', DocumentFieldKey::RowDescription)->source_text)
        ->toBe('15/01 SETORAN TUNAI 2.000.000');

    // Akhir periode menjadi tanggal canonical dokumen (plan.md §8.1).
    expect($document->document_date->toDateString())->toBe('2026-01-31');
});

it('menolak rekening koran yang saldonya tidak dapat direkonsiliasi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('period_end', '2026-01-31'),
            $this->extractedField('opening_balance', '5000000.00'),
            $this->extractedField('closing_balance', '6500000.00'),
        ],
        rows: [
            [
                'date' => '2026-01-15',
                'description' => 'SETORAN TUNAI',
                'debit' => null,
                'credit' => '2000000.00',
                'balance' => '7000000.00',
                'page_number' => 1,
                'source_text' => '15/01 SETORAN TUNAI 2.000.000',
            ],
        ],
        extractor: 'bank_statement',
    );

    $document = $this->runIntelligencePipeline($document);

    /*
     * Baris biaya admin terlewat, sehingga saldo akhir tidak dapat dijelaskan. Kas yang
     * dihasilkan dari mutasi seperti ini tidak akan pernah dapat direkonsiliasi.
     */
    expect($document->extractions()->sole()->validation_errors[0])->toContain('Saldo tidak konsisten');
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect(DocumentField::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('melewati tahap ekstraksi ketika worker belum punya extractor untuk jenisnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('payroll', 0.96);
    $this->fakeExtractorUnavailable();

    $document = $this->runIntelligencePipeline($document);

    /*
     * Memaksakan schema faktur pada slip gaji akan menghasilkan field yang terisi tetapi
     * salah arti, dan itu lebih berbahaya daripada dokumen yang jujur menunggu.
     */
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->review_reason)->toContain('belum tersedia');
    expect($document->extractions()->count())->toBe(0);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Extract)
        ->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Skipped);
});

it('mencatat keputusan pipeline pada timeline tahap ekstraksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.96), confidence: 0.96);

    $document = $this->runIntelligencePipeline($document);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Extract)
        ->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Succeeded);
    expect($job->result['extractor'])->toBe('invoice');
    expect($job->result['field_count'])->toBe(7);
    expect($job->result['confidence']['band'])->toBe('ready');
    expect($job->duration_ms)->not->toBeNull();
});

it('melewati dokumen yang tidak sedang menunggu ekstraksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.96), confidence: 0.96);

    $document = $this->runIntelligencePipeline($document);

    // plan.md §40: job idempotent, sehingga percobaan kedua tidak menghasilkan ekstraksi baru.
    $again = app(App\Domain\Tenancy\TenantContext::class)->withBusiness(
        $business,
        fn (): bool => app(App\Services\DocumentProcessing\DocumentExtractionService::class)->extract($document)
    );

    expect($again)->toBeFalse();
    expect($document->extractions()->count())->toBe(1);
});

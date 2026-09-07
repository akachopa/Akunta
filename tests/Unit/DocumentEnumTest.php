<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentFileKind;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentSourceType;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\InboxFilter;
use App\Domain\Documents\Enums\ProcessingJobStatus;

/**
 * Enum dokumen adalah tempat aturan state machine plan.md §25.1 tinggal, jadi
 * perilakunya diuji tanpa database.
 */
it('memuat seluruh state dokumen pada plan.md 25.1 beserta unsupported', function (): void {
    expect(DocumentStatus::values())->toBe([
        'uploaded',
        'queued',
        'parsing',
        'classifying',
        'extracting',
        'normalizing',
        'matching',
        'ready',
        'need_review',

        // plan.md §5.3 menambahkan UNSUPPORTED di luar daftar §25.1.
        'unsupported',

        'failed',
        'archived',
    ]);
});

it('hanya mengizinkan urutan pipeline yang sah', function (): void {
    expect(DocumentStatus::Uploaded->canTransitionTo(DocumentStatus::Queued))->toBeTrue();
    expect(DocumentStatus::Queued->canTransitionTo(DocumentStatus::Parsing))->toBeTrue();
    expect(DocumentStatus::Parsing->canTransitionTo(DocumentStatus::Classifying))->toBeTrue();

    // Dokumen tidak boleh melompati tahap pipeline.
    expect(DocumentStatus::Uploaded->canTransitionTo(DocumentStatus::Ready))->toBeFalse();
    expect(DocumentStatus::Queued->canTransitionTo(DocumentStatus::Classifying))->toBeFalse();
    expect(DocumentStatus::Parsing->canTransitionTo(DocumentStatus::Extracting))->toBeFalse();
});

it('mengizinkan setiap status yang tidak sedang berjalan untuk diantre ulang', function (): void {
    // plan.md §37 Phase 3 acceptance: "failure dapat diretry".
    expect(DocumentStatus::Failed->isRetryable())->toBeTrue();
    expect(DocumentStatus::Unsupported->isRetryable())->toBeTrue();

    // plan.md §29.3 menyediakan reprocess untuk dokumen apa pun, bukan hanya yang gagal.
    expect(DocumentStatus::Classifying->isRetryable())->toBeTrue();
    expect(DocumentStatus::Ready->isRetryable())->toBeTrue();
    expect(DocumentStatus::Archived->isRetryable())->toBeTrue();

    expect(DocumentStatus::Queued->isRetryable())->toBeFalse();
});

it('tidak menganggap classifying sebagai proses berjalan selama phase 3', function (): void {
    /*
     * Tidak ada worker yang akan memindahkan dokumen dari CLASSIFYING sampai Phase 4,
     * sehingga polling tanpa akhir hanya membebani server.
     */
    expect(DocumentStatus::Classifying->isProcessing())->toBeFalse();

    expect(DocumentStatus::Queued->isProcessing())->toBeTrue();
    expect(DocumentStatus::Parsing->isProcessing())->toBeTrue();
    expect(DocumentStatus::processingValues())->toContain('queued', 'parsing');
    expect(DocumentStatus::processingValues())->not->toContain('classifying');
});

it('menandai kegagalan dan status yang menuntut tindakan user', function (): void {
    expect(DocumentStatus::Failed->isFailure())->toBeTrue();
    expect(DocumentStatus::Unsupported->isFailure())->toBeTrue();
    expect(DocumentStatus::Ready->isFailure())->toBeFalse();

    // plan.md §6: tab "Need Information" dan "Failed" adalah antrean tindakan user.
    expect(DocumentStatus::NeedReview->needsAttention())->toBeTrue();
    expect(DocumentStatus::Unsupported->needsAttention())->toBeTrue();
    expect(DocumentStatus::Failed->needsAttention())->toBeTrue();
    expect(DocumentStatus::Classifying->needsAttention())->toBeFalse();
});

it('memberi label indonesia pada setiap status', function (): void {
    foreach (DocumentStatus::cases() as $status) {
        expect($status->label())->not->toBe('')->and($status->label())->not->toBe($status->value);
    }
});

it('menandai hanya tahap parse yang sudah dibangun', function (): void {
    expect(DocumentProcessingStage::Parse->isImplemented())->toBeTrue();

    foreach ([
        DocumentProcessingStage::Classify,
        DocumentProcessingStage::Extract,
        DocumentProcessingStage::Normalize,
        DocumentProcessingStage::Match,
    ] as $stage) {
        expect($stage->isImplemented())->toBeFalse();
        expect($stage->phase())->toBeGreaterThan(3);
    }
});

it('memetakan setiap tahap ke status dokumen yang sesuai', function (): void {
    expect(DocumentProcessingStage::Parse->runningStatus())->toBe(DocumentStatus::Parsing);
    expect(DocumentProcessingStage::Classify->runningStatus())->toBe(DocumentStatus::Classifying);
    expect(DocumentProcessingStage::Extract->runningStatus())->toBe(DocumentStatus::Extracting);
    expect(DocumentProcessingStage::Normalize->runningStatus())->toBe(DocumentStatus::Normalizing);
    expect(DocumentProcessingStage::Match->runningStatus())->toBe(DocumentStatus::Matching);

    expect(DocumentProcessingStage::Parse->next())->toBe(DocumentProcessingStage::Classify);
    expect(DocumentProcessingStage::Match->next())->toBeNull();
});

it('menyediakan tab inbox sesuai plan.md 6', function (): void {
    expect(InboxFilter::values())->toBe([
        'all',
        'processing',
        'need-information',
        'failed',
        'archived',
    ]);
});

it('memuat seluruh document type pada plan.md 7.1', function (): void {
    expect(DocumentType::values())->toHaveCount(27);
    expect(DocumentType::values())->toContain(
        'bank_statement',
        'sales_invoice',
        'purchase_invoice',
        'qris_settlement',
        'unknown',
    );
});

it('tidak menawarkan unknown sebagai pilihan user saat upload', function (): void {
    /*
     * `unknown` adalah hasil klasifikasi ketika model tidak yakin, bukan pernyataan yang
     * bisa dibuat user.
     */
    $selectable = array_map(
        static fn (DocumentType $type): string => $type->value,
        DocumentType::selectableOnUpload()
    );

    expect($selectable)->not->toContain('unknown');
    expect($selectable)->toContain('bank_statement');
});

it('hanya menyediakan upload sebagai sumber dokumen pada phase 3', function (): void {
    expect(DocumentSourceType::Upload->isAvailable())->toBeTrue();
    expect(DocumentSourceType::Email->isAvailable())->toBeFalse();
    expect(DocumentSourceType::Api->isAvailable())->toBeFalse();
});

it('menandai berkas original sebagai immutable', function (): void {
    // plan.md §37 Phase 3: "original file tetap tersimpan".
    expect(DocumentFileKind::Original->isImmutable())->toBeTrue();
    expect(DocumentFileKind::Thumbnail->isImmutable())->toBeFalse();
});

it('menandai status job yang sudah selesai', function (): void {
    expect(ProcessingJobStatus::Succeeded->isFinished())->toBeTrue();
    expect(ProcessingJobStatus::Failed->isFinished())->toBeTrue();
    expect(ProcessingJobStatus::Skipped->isFinished())->toBeTrue();

    expect(ProcessingJobStatus::Pending->isFinished())->toBeFalse();
    expect(ProcessingJobStatus::Queued->isFinished())->toBeFalse();
    expect(ProcessingJobStatus::Running->isFinished())->toBeFalse();
});

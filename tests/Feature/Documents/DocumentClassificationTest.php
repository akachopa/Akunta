<?php

declare(strict_types=1);

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiRunStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiModelRun;
use App\Domain\Ai\Models\AiUsageLog;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\DocumentTypeSource;
use App\Domain\Documents\Enums\ProcessingJobStatus;
use App\Domain\Documents\Exceptions\DocumentIntelligenceFailed;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ClassifyDocumentJob;
use App\Jobs\ExtractDocumentJob;
use App\Services\DocumentProcessing\DocumentClassificationService;
use App\Services\DocumentProcessing\DocumentProcessingService;
use Illuminate\Support\Facades\Queue;

/**
 * plan.md §14.1 dan §37 Phase 4: klasifikasi dokumen beserta confidence dan alternatifnya.
 *
 * Respons worker di-fake; classifier-nya sendiri diuji pytest di ai-worker.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

/**
 * Membawa dokumen sampai tepat sebelum klasifikasi, lalu menjalankan tahapnya.
 */
function runClassify(Document $document): bool
{
    return app(TenantContext::class)->withBusiness($document->business, function () use ($document): bool {
        app(DocumentProcessingService::class)->process($document);

        return app(DocumentClassificationService::class)->classify($document);
    });
}

it('menetapkan jenis dokumen beserta confidence dari prediksi worker', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.93);

    expect(runClassify($document))->toBeTrue();

    $document->refresh();

    expect($document->document_type)->toBe(DocumentType::PurchaseInvoice);
    expect($document->document_type_source)->toBe(DocumentTypeSource::Ai);
    expect($document->classification_confidence)->toBe('0.9300');
    expect($document->classified_at)->not->toBeNull();
    expect($document->processing_status)->toBe(DocumentStatus::Extracting);
});

it('menyimpan prediksi klasifikasi beserta alternatif dan raw outputnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.88, [
        ['document_type' => 'sales_invoice', 'confidence' => 0.09],
        ['document_type' => 'receipt', 'confidence' => 0.03],
    ]);

    runClassify($document);

    $prediction = $document->predictions()->sole();

    expect($prediction->task)->toBe(AiTask::ClassifyDocument);
    expect($prediction->predicted_value)->toBe('purchase_invoice');
    expect($prediction->confidence)->toBe('0.8800');
    expect($prediction->status)->toBe(AiPredictionStatus::Applied);

    // plan.md §37 Phase 4: raw evidence tetap tersimpan.
    expect($prediction->raw_output)->toHaveKey('document_type');

    // plan.md §14.1: alternatif disimpan berurutan agar reviewer dapat memilih.
    $candidates = $prediction->candidates()->orderBy('rank')->get();

    expect($candidates->pluck('candidate_value')->all())->toBe(['sales_invoice', 'receipt']);
    expect($candidates->pluck('rank')->all())->toBe([1, 2]);
});

it('mencatat panggilan model beserta biayanya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess();

    runClassify($document);

    // plan.md §45.9: provider, model, dan versi prompt harus terlacak per panggilan.
    $run = AiModelRun::query()->where('task', AiTask::ClassifyDocument->value)->sole();

    expect($run->provider)->toBe('heuristic');
    expect($run->model)->toBe('rule-based-v1');
    expect($run->prompt_version)->toBe('classify_document/1');
    expect($run->status)->toBe(AiRunStatus::Succeeded);
    expect($run->latency_ms)->toBe(12);

    // plan.md §32.1: AI_cost_per_document harus dapat dihitung.
    $usage = AiUsageLog::query()->where('model_run_id', $run->getKey())->sole();

    expect($usage->document_id)->toBe($document->getKey());
    expect($usage->input_tokens)->toBe(180);
    expect($usage->cost)->toBe('0.000420');
});

it('tidak menimpa jenis dokumen yang sudah dinyatakan pengunggah', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner, null, DocumentType::BankStatement);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.71);

    runClassify($document);

    $document->refresh();

    // plan.md §17.1: pernyataan manusia mengalahkan prediksi.
    expect($document->document_type)->toBe(DocumentType::BankStatement);
    expect($document->document_type_source)->toBe(DocumentTypeSource::User);

    /*
     * Confidence-nya penuh, bukan sebesar prediksi yang dikalahkan: dokumen yang jenisnya
     * sudah pasti tidak boleh tertahan di antrean review karena model tidak yakin
     * (plan.md §15.1).
     */
    expect($document->classification_confidence)->toBe('1.0000');

    /*
     * Prediksinya tetap disimpan sebagai pembanding akurasi classifier
     * (plan.md §32.2 document_type_accuracy).
     */
    $prediction = $document->predictions()->sole();

    expect($prediction->predicted_value)->toBe('purchase_invoice');
    expect($prediction->status)->toBe(AiPredictionStatus::Superseded);
});

it('menyerahkan dokumen ke reviewer ketika output classifier di luar taksonomi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierOutputRejected();

    // Bukan kegagalan sistem: mengulang panggilan yang sama hanya membakar token.
    expect(runClassify($document))->toBeTrue();

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->document_type)->toBeNull();
    expect($document->review_reason)->toContain('jenis dokumen');

    $prediction = $document->predictions()->sole();

    expect($prediction->status)->toBe(AiPredictionStatus::Rejected);
    expect($prediction->predicted_value)->toBe(DocumentType::Unknown->value);

    $run = AiModelRun::query()->where('task', AiTask::ClassifyDocument->value)->sole();

    expect($run->status)->toBe(AiRunStatus::Rejected);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Classify)
        ->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Skipped);
});

it('melempar ulang kegagalan provider agar queue mencoba lagi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierUnavailable();

    expect(fn (): bool => runClassify($document))->toThrow(DocumentIntelligenceFailed::class);

    $document->refresh();

    // Dokumen dibiarkan CLASSIFYING selama masih ada percobaan tersisa.
    expect($document->processing_status)->toBe(DocumentStatus::Classifying);
    expect($document->document_type)->toBeNull();

    $run = AiModelRun::query()->where('task', AiTask::ClassifyDocument->value)->sole();

    expect($run->status)->toBe(AiRunStatus::Failed);
    expect($run->error_class)->toBe(DocumentIntelligenceFailed::class);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Classify)
        ->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Failed);
});

it('mengirim halaman hasil parse ke worker alih-alih berkasnya kembali', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess();

    runClassify($document);

    // plan.md §13.2: halaman sudah tersimpan sejak parse, jadi berkasnya tidak dikirim ulang.
    Illuminate\Support\Facades\Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($business): bool {
        if (! str_ends_with($request->url(), '/v1/classify')) {
            return false;
        }

        return $request['business_context'] === $business->name
            && ! $request->isMultipart()
            && $request['pages'][0]['rows'][1] === ['2026-01-15', 'SETORAN TUNAI', '2000000'];
    });
});

it('mengirim job ekstraksi setelah klasifikasi berhasil', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess();

    app(TenantContext::class)->withBusiness($business, function () use ($document, $business): void {
        app(DocumentProcessingService::class)->process($document);

        (new ClassifyDocumentJob($document->getKey(), $business->getKey()))
            ->handle(app(TenantContext::class));
    });

    Queue::assertPushed(ExtractDocumentJob::class);
});

it('melewati dokumen yang tidak sedang menunggu klasifikasi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess();

    runClassify($document);

    // plan.md §40: job yang terkirim dua kali tidak boleh menghasilkan prediksi kedua.
    $again = app(TenantContext::class)->withBusiness(
        $business,
        fn (): bool => app(DocumentClassificationService::class)->classify($document->refresh())
    );

    expect($again)->toBeFalse();
    expect($document->predictions()->count())->toBe(1);
});

<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentTypeSource;
use App\Domain\Documents\Exceptions\DocumentOutputRejected;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentProcessingJob;
use App\Services\Ai\AiPredictionRecorder;
use App\Services\Ai\AiRunRecorder;
use App\Services\Ai\AiWorkerClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tahap klasifikasi dokumen (plan.md §14.1, §37 Phase 4).
 *
 * Tugasnya satu: menetapkan jenis dokumen beserta confidence-nya, lalu menyerahkan dokumen
 * ke tahap ekstraksi. Ia tidak membaca field apa pun — plan.md §44.1 melarang satu prompt
 * mengerjakan seluruh pipeline, dan memisahkan keduanya membuat kesalahan dapat dilokalisasi:
 * dokumen yang salah dikenali jenisnya terlihat berbeda dari dokumen yang jenisnya benar
 * tetapi angkanya salah baca.
 *
 * Jenis dokumen yang sudah dinyatakan manusia tidak pernah ditimpa prediksi (plan.md §17.1).
 * Prediksinya tetap dijalankan dan disimpan, dengan status `superseded`, karena
 * membandingkan prediksi terhadap pernyataan manusia adalah cara mengukur akurasi
 * classifier (plan.md §32.2).
 */
class DocumentClassificationService
{
    public function __construct(
        private readonly AiWorkerClient $worker,
        private readonly DocumentStageRecorder $stages,
        private readonly AiRunRecorder $runs,
        private readonly AiPredictionRecorder $predictions,
    ) {}

    /**
     * Mengembalikan false bila dokumen memang tidak menunggu klasifikasi, misalnya karena
     * job terkirim dua kali atau karena dokumen sudah diarsipkan sejak job dikirim.
     */
    public function classify(Document $document): bool
    {
        $document->refresh();

        if ($document->processing_status !== DocumentStatus::Classifying) {
            return false;
        }

        $job = $this->stages->currentOrOpen($document, DocumentProcessingStage::Classify);
        $job->markRunning();

        try {
            $result = $this->worker->classifyDocument(
                filename: $document->original_filename,
                mimeType: $document->mime_type,
                pages: ParsedPagePayload::forDocument($document),
                businessContext: $document->business->name,
            );
        } catch (DocumentOutputRejected $exception) {
            $this->rejectOutput($document, $job, $exception);

            return true;
        } catch (Throwable $exception) {
            /*
             * Percobaan yang gagal dicatat, tetapi status dokumen dibiarkan CLASSIFYING dan
             * exception-nya dilempar ulang supaya queue mencoba lagi dengan backoff. FAILED
             * baru ditetapkan setelah seluruh percobaan habis.
             */
            $this->runs->failed($document, AiTask::ClassifyDocument, $exception);
            $job->markFailed($exception);

            throw $exception;
        }

        DB::transaction(function () use ($document, $job, $result): void {
            $run = $this->runs->succeeded(
                document: $document,
                task: AiTask::ClassifyDocument,
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion,
                schemaVersion: null,
                latencyMs: $result->latencyMs,
                usage: $result->usage,
            );

            $declaredByHuman = $document->hasHumanDocumentType();

            $this->predictions->classification(
                document: $document,
                run: $run,
                result: $result,
                status: $declaredByHuman ? AiPredictionStatus::Superseded : AiPredictionStatus::Applied,
            );

            if (! $declaredByHuman) {
                $document->document_type = $result->documentType;
                $document->document_type_source = DocumentTypeSource::Ai;
            }

            /*
             * Ketika jenis dokumen berasal dari manusia, komponen `document_type_confidence`
             * bernilai penuh, bukan sebesar keyakinan prediksi yang baru saja dikalahkan.
             * Memakai angka AI di sini akan menahan dokumen yang jenisnya sudah pasti di
             * antrean review hanya karena model tidak yakin (plan.md §15.1).
             */
            $document->classification_confidence = $declaredByHuman ? '1.0000' : $result->confidence;
            $document->classified_at = Carbon::now();

            $document->transitionTo(DocumentStatus::Extracting);

            $job->markSucceeded([
                'document_type' => $document->document_type?->value,
                'document_type_source' => $document->document_type_source?->value,
                'confidence' => $document->classification_confidence,
                'provider' => $result->provider,
                'model' => $result->model,
                'prompt_version' => $result->promptVersion,
                'predicted_document_type' => $result->documentType->value,
            ]);
        });

        return true;
    }

    /**
     * Output di luar taksonomi plan.md §7.1.
     *
     * Tidak diretry: panggilan yang sama dengan masukan yang sama akan menghasilkan
     * penolakan yang sama, dan hanya membakar token. Dokumennya diserahkan ke reviewer
     * beserta alasan penolakannya.
     */
    private function rejectOutput(
        Document $document,
        DocumentProcessingJob $job,
        DocumentOutputRejected $exception,
    ): void {
        DB::transaction(function () use ($document, $job, $exception): void {
            $run = $this->runs->rejected(
                document: $document,
                task: AiTask::ClassifyDocument,
                provider: 'unknown',
                model: 'unknown',
                promptVersion: '0',
                schemaVersion: null,
                latencyMs: null,
                usage: null,
                reason: $exception->getMessage(),
            );

            $this->predictions->rejectedClassification($document, $run, $exception->reasons);

            $job->markSkipped($exception->getMessage());

            $document->transitionTo(DocumentStatus::NeedReview, [
                'review_reason' => 'Jenis dokumen tidak dapat dipastikan otomatis. Mohon pilih jenis dokumennya.',
            ]);
        });
    }
}

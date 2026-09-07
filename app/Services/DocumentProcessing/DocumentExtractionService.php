<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiModelRun;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Exceptions\DocumentOutputRejected;
use App\Domain\Documents\Exceptions\NoExtractorAvailable;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Models\DocumentProcessingJob;
use App\Services\Ai\AiRunRecorder;
use App\Services\Ai\AiWorkerClient;
use App\Services\Ai\ConfidenceEngine;
use App\Services\Ai\DocumentExtractionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tahap ekstraksi terstruktur (plan.md §14.2, §37 Phase 4).
 *
 * Di sini seluruh acceptance Phase 4 bertemu: output terstruktur tersimpan sebagai field,
 * confidence-nya tercatat, bukti mentah dan hasil olahannya sama-sama disimpan, dan output
 * yang tidak lolos validasi tidak menghasilkan satu pun nilai yang dapat dibaca sebagai
 * data sah.
 *
 * Tiga hasil yang mungkin, dan ketiganya berakhir berbeda:
 *
 * - **Diterima.** Field tersimpan, kolom canonical §8.1 terisi, dan confidence engine §15
 *   memutuskan apakah dokumen lanjut ke normalisasi transaksi (Phase 5) atau masuk antrean
 *   review.
 * - **Ditolak.** Ekstraksi tersimpan berstatus `rejected` lengkap dengan alasan dan raw
 *   output, tanpa satu pun `document_fields`. Dokumen menunggu manusia.
 * - **Tidak dapat dikerjakan.** Jenis dokumennya belum punya extractor; tahapnya dilewati
 *   dan dokumen menunggu manusia. Memaksakan schema faktur pada slip gaji akan menghasilkan
 *   field yang terisi tetapi salah arti, dan itu lebih berbahaya daripada dokumen yang
 *   jujur menunggu.
 */
class DocumentExtractionService
{
    public function __construct(
        private readonly AiWorkerClient $worker,
        private readonly DocumentStageRecorder $stages,
        private readonly AiRunRecorder $runs,
        private readonly ExtractionValidator $validator,
        private readonly ConfidenceEngine $confidence,
        private readonly DocumentFieldWriter $fields,
    ) {}

    public function extract(Document $document): bool
    {
        $document->refresh();

        if ($document->processing_status !== DocumentStatus::Extracting) {
            return false;
        }

        $job = $this->stages->currentOrOpen($document, DocumentProcessingStage::Extract);
        $job->markRunning();

        $documentType = $document->document_type;

        if ($documentType === null || $documentType === DocumentType::Unknown) {
            $this->skip(
                $document,
                $job,
                'Jenis dokumen belum dapat dipastikan, sehingga datanya belum dapat dibaca otomatis.',
                'Jenis dokumen belum dapat dipastikan. Mohon pilih jenis dokumennya agar datanya dapat dibaca.'
            );

            return true;
        }

        try {
            $result = $this->worker->extractDocument(
                documentType: $documentType->value,
                pages: ParsedPagePayload::forDocument($document),
                businessContext: $document->business->name,
            );
        } catch (NoExtractorAvailable $exception) {
            $this->skip(
                $document,
                $job,
                $exception->getMessage(),
                sprintf(
                    'Dokumen dikenali sebagai %s, tetapi pembacaan otomatis untuk jenis ini belum tersedia.',
                    $documentType->label()
                )
            );

            return true;
        } catch (DocumentOutputRejected $exception) {
            $this->rejectWorkerOutput($document, $job, $documentType, $exception);

            return true;
        } catch (Throwable $exception) {
            $this->runs->failed($document, AiTask::ExtractDocument, $exception);
            $job->markFailed($exception);

            throw $exception;
        }

        $validated = $this->validator->validate($documentType, $result);

        DB::transaction(function () use ($document, $job, $documentType, $result, $validated): void {
            $run = $validated->isAccepted()
                ? $this->runs->succeeded(
                    document: $document,
                    task: AiTask::ExtractDocument,
                    provider: $result->provider,
                    model: $result->model,
                    promptVersion: $result->promptVersion,
                    schemaVersion: $result->schemaVersion,
                    latencyMs: $result->latencyMs,
                    usage: $result->usage,
                )
                : $this->runs->rejected(
                    document: $document,
                    task: AiTask::ExtractDocument,
                    provider: $result->provider,
                    model: $result->model,
                    promptVersion: $result->promptVersion,
                    schemaVersion: $result->schemaVersion,
                    latencyMs: $result->latencyMs,
                    usage: $result->usage,
                    reason: implode(' ', $validated->errors),
                );

            $extraction = $this->recordExtraction($document, $run, $documentType, $result, $validated);

            if (! $validated->isAccepted()) {
                $job->markSkipped(implode(' ', $validated->errors));

                $document->transitionTo(DocumentStatus::NeedReview, [
                    'extracted_at' => Carbon::now(),
                    'review_reason' => $this->rejectionReason($validated->errors),
                ]);

                return;
            }

            $this->fields->write($document, $extraction, $validated->fields);

            $assessment = $this->confidence->evaluate([
                'document_type_confidence' => $document->classification_confidence,
                'extraction_confidence' => $result->confidence,
            ], $document->business);

            $document->transitionTo(
                $assessment->band->nextDocumentStatus(),
                $this->fields->canonicalAttributes($validated->documentFields()) + [
                    'extraction_confidence' => $result->confidence,
                    'extracted_at' => Carbon::now(),
                    'review_reason' => $assessment->reviewReason(),
                ]
            );

            $job->markSucceeded([
                'extractor' => $result->extractor,
                'schema_version' => $result->schemaVersion,
                'provider' => $result->provider,
                'model' => $result->model,
                'field_count' => count($validated->documentFields()),
                'row_count' => count($result->rows),
                'extraction_confidence' => $result->confidence,
                'confidence' => $assessment->toArray(),
            ]);
        });

        return true;
    }

    /**
     * Menyimpan satu percobaan ekstraksi beserta buktinya (plan.md §23.3).
     *
     * Raw output selalu disertai verdict worker. Reviewer perlu melihat keduanya: apa yang
     * dijawab provider, dan apa yang dianggap salah oleh worker maupun oleh Laravel
     * (plan.md §37 Phase 4 "raw + parsed evidence").
     */
    private function recordExtraction(
        Document $document,
        AiModelRun $run,
        DocumentType $documentType,
        DocumentExtractionResult $result,
        ValidatedExtraction $validated,
    ): DocumentExtraction {
        $attempt = (int) DocumentExtraction::query()
            ->where('document_id', $document->getKey())
            ->max('attempt');

        /** @var DocumentExtraction $extraction */
        $extraction = $document->extractions()->create([
            'business_id' => $document->business_id,
            'model_run_id' => $run->getKey(),
            'document_type' => $documentType,
            'extractor' => $result->extractor,
            'schema_version' => $result->schemaVersion,
            'status' => $validated->status(),
            'confidence' => $result->confidence,
            'validation_errors' => $validated->errors === [] ? null : $validated->errors,
            'raw_output' => [
                'provider_output' => $result->raw,
                'worker_valid' => $result->valid,
                'worker_validation_errors' => $result->validationErrors,
            ],
            'attempt' => $attempt + 1,
        ]);

        return $extraction;
    }

    /**
     * Output provider melanggar schema, ditolak worker sebelum sampai ke Laravel.
     *
     * Ekstraksi tetap dicatat sebagai percobaan yang ditolak. Tanpa barisnya, dokumen yang
     * menunggu reviewer tidak memiliki jejak bahwa ekstraksinya pernah dijalankan.
     */
    private function rejectWorkerOutput(
        Document $document,
        DocumentProcessingJob $job,
        DocumentType $documentType,
        DocumentOutputRejected $exception,
    ): void {
        DB::transaction(function () use ($document, $job, $documentType, $exception): void {
            $run = $this->runs->rejected(
                document: $document,
                task: AiTask::ExtractDocument,
                provider: 'unknown',
                model: 'unknown',
                promptVersion: '0',
                schemaVersion: null,
                latencyMs: null,
                usage: null,
                reason: $exception->getMessage(),
            );

            $reasons = $exception->reasons !== [] ? $exception->reasons : [$exception->getMessage()];

            $attempt = (int) DocumentExtraction::query()
                ->where('document_id', $document->getKey())
                ->max('attempt');

            $document->extractions()->create([
                'business_id' => $document->business_id,
                'model_run_id' => $run->getKey(),
                'document_type' => $documentType,
                'extractor' => 'unknown',
                'schema_version' => '0',
                'status' => ExtractionStatus::Rejected,
                'confidence' => null,
                'validation_errors' => $reasons,
                'raw_output' => ['worker_valid' => false, 'worker_validation_errors' => $reasons],
                'attempt' => $attempt + 1,
            ]);

            $job->markSkipped($exception->getMessage());

            $document->transitionTo(DocumentStatus::NeedReview, [
                'extracted_at' => Carbon::now(),
                'review_reason' => $this->rejectionReason($reasons),
            ]);
        });
    }

    /**
     * Tahap tidak dapat dikerjakan, dan mengulangnya tidak akan mengubah hasilnya.
     */
    private function skip(
        Document $document,
        DocumentProcessingJob $job,
        string $jobReason,
        string $reviewReason,
    ): void {
        DB::transaction(function () use ($document, $job, $jobReason, $reviewReason): void {
            $job->markSkipped($jobReason);

            $document->transitionTo(DocumentStatus::NeedReview, [
                'review_reason' => $reviewReason,
            ]);
        });
    }

    /**
     * plan.md §16.3 melarang pertanyaan teknis ke user.
     *
     * Alasan teknis tetap tersimpan lengkap pada `document_extractions.validation_errors`
     * untuk reviewer yang ingin memeriksanya; yang muncul di inbox adalah ringkasan yang
     * dapat dipahami pemilik usaha.
     *
     * @param  array<int, string>  $errors
     */
    private function rejectionReason(array $errors): string
    {
        return mb_strimwidth(
            'Data pada dokumen belum dapat dipastikan: ' . ($errors[0] ?? 'hasil pembacaan tidak konsisten.'),
            0,
            1000,
            '…'
        );
    }
}

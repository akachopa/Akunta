<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiModelRun;
use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use Illuminate\Support\Carbon;

/**
 * Mencatat prediksi klasifikasi beserta alternatifnya (plan.md §14.1, §23.6).
 *
 * Prediksi disimpan sebagai record tersendiri, bukan hanya sebagai kolom pada dokumen,
 * karena plan.md §31 melarang menimpa keputusan historis. Ketika reviewer mengoreksi jenis
 * dokumen, prediksi aslinya beserta confidence-nya harus tetap terbaca: itulah satu-satunya
 * bahan pengukuran `document_type_accuracy` pada plan.md §32.2.
 */
class AiPredictionRecorder
{
    public function classification(
        Document $document,
        ?AiModelRun $run,
        DocumentClassificationResult $result,
        AiPredictionStatus $status,
    ): AiPrediction {
        $prediction = $this->create(
            document: $document,
            run: $run,
            predictedValue: $result->documentType->value,
            confidence: $result->confidence,
            reason: $result->reason,
            status: $status,
            rawOutput: $result->raw === [] ? null : $result->raw,
        );

        $this->recordCandidates($prediction, $result);

        return $prediction;
    }

    /**
     * Output provider di luar taksonomi (plan.md §37 Phase 4).
     *
     * Tetap dicatat sebagai prediksi berstatus `rejected`. Tanpa barisnya, dokumen yang
     * menunggu reviewer tidak memiliki jejak mengapa klasifikasinya tidak pernah terisi,
     * dan kegagalan berulang sebuah provider tidak terlihat pada metrik apa pun.
     *
     * @param  array<int, string>  $reasons
     */
    public function rejectedClassification(
        Document $document,
        ?AiModelRun $run,
        array $reasons,
    ): AiPrediction {
        return $this->create(
            document: $document,
            run: $run,
            predictedValue: DocumentType::Unknown->value,
            confidence: '0',
            reason: implode(' ', $reasons) ?: null,
            status: AiPredictionStatus::Rejected,
            rawOutput: ['validation_errors' => $reasons],
        );
    }

    /**
     * Menandai seluruh prediksi yang sedang dipakai sebagai dikalahkan manusia.
     *
     * Dipakai ketika reviewer menetapkan jenis dokumen. Barisnya tidak dihapus; hanya
     * statusnya berubah (plan.md §31).
     */
    public function supersede(Document $document, AiTask $task): void
    {
        $document->predictions()
            ->where('task', $task->value)
            ->where('status', AiPredictionStatus::Applied->value)
            ->get()
            ->each(static function (AiPrediction $prediction): void {
                $prediction->markSuperseded();
            });
    }

    /**
     * @param  array<string, mixed>|null  $rawOutput
     */
    private function create(
        Document $document,
        ?AiModelRun $run,
        string $predictedValue,
        string $confidence,
        ?string $reason,
        AiPredictionStatus $status,
        ?array $rawOutput,
    ): AiPrediction {
        /** @var AiPrediction $prediction */
        $prediction = $document->predictions()->create([
            'business_id' => $document->business_id,
            'model_run_id' => $run?->getKey(),
            'task' => AiTask::ClassifyDocument,
            'predicted_value' => $predictedValue,
            'confidence' => $confidence,
            'reason' => $reason === null ? null : mb_strimwidth($reason, 0, 1000, '…'),
            'status' => $status,
            'applied_at' => $status === AiPredictionStatus::Applied ? Carbon::now() : null,
            'raw_output' => $rawOutput,
        ]);

        return $prediction;
    }

    private function recordCandidates(AiPrediction $prediction, DocumentClassificationResult $result): void
    {
        $rank = 1;
        $recorded = [$result->documentType->value];

        foreach ($result->alternatives as $alternative) {
            $value = $alternative['document_type']->value;

            /*
             * Jawaban utama tidak diulang sebagai kandidat, dan kandidat kembar dibuang.
             * Keduanya akan ditolak unique index, dan menolaknya di sini menjaga panggilan
             * provider yang cerewet tidak menggagalkan seluruh klasifikasi.
             */
            if (in_array($value, $recorded, true)) {
                continue;
            }

            $prediction->candidates()->create([
                'business_id' => $prediction->business_id,
                'candidate_value' => $value,
                'confidence' => $alternative['confidence'],
                'rank' => $rank,
            ]);

            $recorded[] = $value;
            $rank++;
        }
    }
}

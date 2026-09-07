<?php

declare(strict_types=1);

namespace App\Services\EconomicEvents;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Accounting\Models\EconomicEventPrediction;
use App\Domain\Accounting\Models\EconomicEventType;
use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiFeedback;
use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiWorkerClient;
use App\Services\Ai\ConfidenceEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Klasifikasi economic event (plan.md §14.3, §37 Phase 8).
 *
 * Heuristic jalan lebih dulu. Worker hanya dipanggil ketika heuristic tidak yakin, dan
 * outputnya ditolak bila kodenya di luar taksonomi (plan.md §14.3).
 */
class EconomicEventClassificationService
{
    public function __construct(
        private readonly EconomicEventHeuristic $heuristic,
        private readonly AiWorkerClient $worker,
        private readonly ConfidenceEngine $confidence,
    ) {}

    /**
     * @return array{event: string, confidence: string, missing: array<int, string>}
     */
    public function classify(Transaction $transaction): array
    {
        $transaction->loadMissing(['source.document', 'business']);

        $local = $this->heuristic->classify($transaction);
        $result = $local;
        $source = 'heuristic';
        $raw = null;

        if (! $this->confidence->band($local->confidence, $this->confidence->autoReadyThreshold($transaction->business), $this->confidence->reviewThreshold($transaction->business))->allowsAutomaticProcessing()) {
            $remote = $this->askWorker($transaction);

            if ($remote instanceof HeuristicClassification) {
                $result = $remote;
                $source = 'model';
                $raw = ['source' => 'worker'];
            }
        }

        $type = EconomicEventType::byCode($result->event);

        DB::transaction(function () use ($transaction, $result, $type, $source, $raw): void {
            $this->supersede($transaction);

            $prediction = $transaction->predictions()->create([
                'business_id' => $transaction->business_id,
                'task' => AiTask::ClassifyEconomicEvent,
                'predicted_value' => $result->event->value,
                'confidence' => $result->confidence,
                'reason' => mb_strimwidth($result->reason, 0, 1000, '…'),
                'status' => AiPredictionStatus::Applied,
                'applied_at' => Carbon::now(),
                'raw_output' => $raw,
            ]);

            EconomicEventPrediction::query()->create([
                'business_id' => $transaction->business_id,
                'transaction_id' => $transaction->getKey(),
                'event_type_id' => $type->getKey(),
                'prediction_id' => $prediction->getKey(),
                'confidence' => $result->confidence,
                'reason' => mb_strimwidth($result->reason, 0, 1000, '…'),
                'missing_information' => $result->missingInformation === [] ? null : $result->missingInformation,
                'source' => $source,
                'status' => AiPredictionStatus::Applied,
                'applied_at' => Carbon::now(),
            ]);

            $transaction->forceFill([
                'economic_event_id' => $type->getKey(),
            ])->save();
        });

        return [
            'event' => $result->event->value,
            'confidence' => $result->confidence,
            'missing' => $result->missingInformation,
        ];
    }

    /**
     * Koreksi reviewer (plan.md §17.1, acceptance Phase 8).
     */
    public function correct(Transaction $transaction, EconomicEventCode $event, User $actor, ?string $reason = null): void
    {
        $transaction->loadMissing(['economicEvent', 'source.document']);

        $type = EconomicEventType::byCode($event);

        DB::transaction(function () use ($transaction, $event, $type, $actor, $reason): void {
            $previousCode = $transaction->economicEvent?->code;

            $this->supersede($transaction);

            EconomicEventPrediction::query()->create([
                'business_id' => $transaction->business_id,
                'transaction_id' => $transaction->getKey(),
                'event_type_id' => $type->getKey(),
                'prediction_id' => null,
                'confidence' => '1.0000',
                'reason' => $reason ?? 'Ditetapkan oleh reviewer.',
                'missing_information' => null,
                'source' => 'user',
                'status' => AiPredictionStatus::Applied,
                'applied_at' => Carbon::now(),
            ]);

            $transaction->forceFill([
                'economic_event_id' => $type->getKey(),
            ])->save();

            if ($previousCode !== $event->value) {
                AiFeedback::query()->create([
                    'business_id' => $transaction->business_id,
                    'document_id' => $transaction->sourceDocument?->getKey(),
                    'task' => AiTask::ClassifyEconomicEvent,
                    'original_value' => $previousCode,
                    'final_value' => $event->value,
                    'reviewer_id' => $actor->getKey(),
                    'reason' => $reason,
                ]);
            }
        });
    }

    private function askWorker(Transaction $transaction): ?HeuristicClassification
    {
        try {
            $payload = $this->worker->classifyEconomicEvent(
                description: $transaction->description,
                amount: $transaction->amount,
                direction: $transaction->direction->value,
                documentType: $transaction->sourceDocument?->document_type?->value,
                counterparty: $transaction->counterparty_name,
                businessContext: $transaction->business->name,
            );
        } catch (Throwable) {
            return null;
        }

        $code = EconomicEventCode::tryFrom($payload['event_code']);

        if (! $code instanceof EconomicEventCode) {
            return null;
        }

        $confidence = $this->confidence->normalize((string) $payload['confidence']);

        /** @var array<int, string> $missing */
        $missing = $payload['missing_information'];

        return new HeuristicClassification(
            $code,
            $confidence,
            (string) $payload['reason'],
            $missing,
        );
    }

    private function supersede(Transaction $transaction): void
    {
        $transaction->predictions()
            ->where('task', AiTask::ClassifyEconomicEvent->value)
            ->where('status', AiPredictionStatus::Applied->value)
            ->get()
            ->each(static function (AiPrediction $prediction): void {
                $prediction->markSuperseded();
            });

        EconomicEventPrediction::query()
            ->where('transaction_id', $transaction->getKey())
            ->where('status', AiPredictionStatus::Applied->value)
            ->get()
            ->each(static function (EconomicEventPrediction $prediction): void {
                $prediction->markSuperseded();
            });
    }
}

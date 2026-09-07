<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\Enums\AiRunStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiModelRun;
use App\Domain\Ai\Models\AiUsageLog;
use App\Domain\Documents\Models\Document;
use Throwable;

/**
 * Mencatat setiap panggilan provider AI (plan.md §45.9, §32.1).
 *
 * Dua hal dicatat dalam satu tindakan karena keduanya harus selalu muncul bersama: catatan
 * teknis panggilannya pada `ai_model_runs`, dan biayanya pada `ai_usage_logs`. Memisahkan
 * pemanggilannya akan membuka kemungkinan panggilan yang tercatat tanpa biaya, dan metrik
 * `AI_cost_per_document` menjadi lebih rendah dari kenyataan.
 *
 * Panggilan yang gagal juga dicatat. Biayanya nol, tetapi tanpa barisnya tidak ada cara
 * mengetahui bahwa sebuah dokumen sudah dicoba beberapa kali dan provider-nya yang
 * bermasalah.
 */
class AiRunRecorder
{
    public function succeeded(
        Document $document,
        AiTask $task,
        string $provider,
        string $model,
        string $promptVersion,
        ?string $schemaVersion,
        int $latencyMs,
        AiProviderUsage $usage,
    ): AiModelRun {
        return $this->record(
            document: $document,
            task: $task,
            status: AiRunStatus::Succeeded,
            provider: $provider,
            model: $model,
            promptVersion: $promptVersion,
            schemaVersion: $schemaVersion,
            latencyMs: $latencyMs,
            usage: $usage,
        );
    }

    /**
     * Provider merespons, tetapi outputnya melanggar kontrak.
     *
     * Usage tetap dicatat ketika diketahui: token sudah terpakai meski jawabannya tidak
     * dapat dipakai, dan biaya yang tidak dicatat akan membuat pemborosan provider yang
     * buruk tidak terlihat. Nilainya null hanya ketika penolakan terjadi di worker sebelum
     * responsnya sampai ke Laravel, sehingga tidak ada angka yang dapat dipercaya.
     */
    public function rejected(
        Document $document,
        AiTask $task,
        string $provider,
        string $model,
        string $promptVersion,
        ?string $schemaVersion,
        ?int $latencyMs,
        ?AiProviderUsage $usage,
        string $reason,
    ): AiModelRun {
        return $this->record(
            document: $document,
            task: $task,
            status: AiRunStatus::Rejected,
            provider: $provider,
            model: $model,
            promptVersion: $promptVersion,
            schemaVersion: $schemaVersion,
            latencyMs: $latencyMs,
            usage: $usage,
            errorClass: null,
            errorMessage: $reason,
        );
    }

    public function failed(Document $document, AiTask $task, Throwable $exception): AiModelRun
    {
        return $this->record(
            document: $document,
            task: $task,
            status: AiRunStatus::Failed,
            provider: 'unknown',
            model: 'unknown',
            promptVersion: '0',
            schemaVersion: null,
            latencyMs: null,
            usage: null,
            errorClass: $exception::class,
            errorMessage: $this->redact($exception->getMessage()),
        );
    }

    private function record(
        Document $document,
        AiTask $task,
        AiRunStatus $status,
        string $provider,
        string $model,
        string $promptVersion,
        ?string $schemaVersion,
        ?int $latencyMs,
        ?AiProviderUsage $usage,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): AiModelRun {
        /** @var AiModelRun $run */
        $run = AiModelRun::query()->create([
            'business_id' => $document->business_id,
            'task' => $task,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'error_class' => $errorClass,
            'error_message' => $errorMessage === null ? null : $this->redact($errorMessage),
        ]);

        AiUsageLog::query()->create([
            'business_id' => $document->business_id,
            'model_run_id' => $run->getKey(),
            'document_id' => $document->getKey(),
            'task' => $task,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $usage->inputTokens ?? 0,
            'output_tokens' => $usage->outputTokens ?? 0,
            'cost' => $usage->cost ?? '0',
            'currency' => $usage->currency ?? 'USD',
        ]);

        return $run;
    }

    /**
     * plan.md §30 mewajibkan redaksi log data sensitif.
     *
     * Pesan kesalahan provider dapat memuat cuplikan dokumen yang dikirimkan, jadi
     * panjangnya dipotong sebelum disimpan.
     */
    private function redact(string $message): string
    {
        return mb_strimwidth($message, 0, 1000, '…');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\ProcessingJobStatus;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * plan.md §23.3: document_processing_jobs.
 *
 * @property string $id
 * @property string $document_id
 * @property string $business_id
 * @property DocumentProcessingStage $stage
 * @property ProcessingJobStatus $status
 * @property int $attempt
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string|null $error_class
 * @property string|null $error_message
 * @property array<string, mixed>|null $result
 * @property Document $document
 */
class DocumentProcessingJob extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'document_id',
        'business_id',
        'stage',
        'status',
        'attempt',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_class',
        'error_message',
        'result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DocumentProcessingStage::class,
            'status' => ProcessingJobStatus::class,
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'result' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function markRunning(): void
    {
        $this->status = ProcessingJobStatus::Running;
        $this->started_at = Carbon::now();
        $this->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markSucceeded(array $result = []): void
    {
        $this->finish(ProcessingJobStatus::Succeeded);
        $this->result = $result;
        $this->save();
    }

    public function markFailed(Throwable $exception): void
    {
        $this->finish(ProcessingJobStatus::Failed);
        $this->error_class = $exception::class;

        // plan.md §30 mewajibkan redaksi log sensitif. Pesan exception dipotong supaya
        // isi dokumen tidak ikut tersimpan bila ada library yang menyertakannya.
        $this->error_message = mb_strimwidth($exception->getMessage(), 0, 1000, '…');

        $this->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->finish(ProcessingJobStatus::Skipped);
        $this->error_message = $reason;
        $this->save();
    }

    private function finish(ProcessingJobStatus $status): void
    {
        $finishedAt = Carbon::now();

        $this->status = $status;
        $this->finished_at = $finishedAt;

        if ($this->started_at !== null) {
            $this->duration_ms = (int) round($this->started_at->diffInMilliseconds($finishedAt, true));
        }
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForStage(Builder $query, DocumentProcessingStage $stage): Builder
    {
        return $query->where('stage', $stage->value);
    }
}

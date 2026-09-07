<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23.6: ai_predictions.
 *
 * Prediksi tidak dapat diubah setelah dibuat, kecuali statusnya ketika dikalahkan manusia.
 * plan.md §31 melarang keputusan historis ditimpa, dan prediksi asli beserta confidence-nya
 * adalah satu-satunya dasar pengukuran akurasi pada plan.md §32.2.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $model_run_id
 * @property string $subject_type
 * @property string $subject_id
 * @property AiTask $task
 * @property string $predicted_value
 * @property string $confidence
 * @property string|null $reason
 * @property AiPredictionStatus $status
 * @property Carbon|null $applied_at
 * @property array<string, mixed>|null $raw_output
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiPrediction extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'model_run_id',
        'subject_type',
        'subject_id',
        'task',
        'predicted_value',
        'confidence',
        'reason',
        'status',
        'applied_at',
        'raw_output',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'status' => AiPredictionStatus::class,
            'applied_at' => 'datetime',
            'raw_output' => 'array',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Hanya status dan waktu penerapan yang boleh berubah. Nilai prediksi maupun
         * confidence-nya tidak: mengubahnya sama dengan menghapus jejak keputusan AI, yang
         * dilarang plan.md §44.7.
         */
        static::updating(function (self $prediction): void {
            $mutable = ['status', 'applied_at', 'updated_at'];
            $changed = array_keys($prediction->getDirty());

            if (array_diff($changed, $mutable) !== []) {
                throw new RuntimeException(
                    'Prediksi AI tidak dapat diubah selain statusnya (plan.md §31).'
                );
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Prediksi AI tidak dapat dihapus (plan.md §44.7).');
        });
    }

    /**
     * Subjek prediksi. Pada Phase 4 selalu dokumen; transaksi dan economic event menyusul.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /**
     * @return BelongsTo<AiModelRun, $this>
     */
    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(AiModelRun::class, 'model_run_id');
    }

    /**
     * plan.md §14.1: alternatif berperingkat.
     *
     * @return HasMany<AiPredictionCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(AiPredictionCandidate::class, 'prediction_id')->orderBy('rank');
    }

    /**
     * Menandai prediksi dikalahkan pernyataan atau koreksi manusia.
     */
    public function markSuperseded(): void
    {
        if ($this->status === AiPredictionStatus::Superseded) {
            return;
        }

        $this->status = AiPredictionStatus::Superseded;
        $this->save();
    }
}

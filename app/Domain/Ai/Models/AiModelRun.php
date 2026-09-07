<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Enums\AiRunStatus;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.6: ai_model_runs.
 *
 * Catatan teknis satu panggilan provider. Tidak memuat isi request maupun response, karena
 * keduanya berisi potongan dokumen finansial dan plan.md §30 mewajibkan redaksi data
 * sensitif pada log.
 *
 * @property string $id
 * @property string $business_id
 * @property AiTask $task
 * @property string $provider
 * @property string $model
 * @property string $prompt_version
 * @property string|null $schema_version
 * @property AiRunStatus $status
 * @property int|null $latency_ms
 * @property string|null $error_class
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiModelRun extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'task',
        'provider',
        'model',
        'prompt_version',
        'schema_version',
        'status',
        'latency_ms',
        'error_class',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'status' => AiRunStatus::class,
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return HasOne<AiUsageLog, $this>
     */
    public function usage(): HasOne
    {
        return $this->hasOne(AiUsageLog::class, 'model_run_id');
    }

    /**
     * @return HasMany<AiPrediction, $this>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(AiPrediction::class, 'model_run_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.6: ai_usage_logs.
 *
 * Buku biaya pemakaian AI, sumber metrik plan.md §32.1 `AI_cost_per_document` dan
 * `AI_cost_per_business`.
 *
 * `cost` tidak di-cast menjadi float. plan.md §44.14 melarang float untuk uang, dan biaya
 * AI tetap uang meski satuannya dolar; nilainya dibaca sebagai string desimal dan
 * dijumlahkan oleh database.
 *
 * @property string $id
 * @property string $business_id
 * @property string $model_run_id
 * @property string|null $document_id
 * @property AiTask $task
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiUsageLog extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'model_run_id',
        'document_id',
        'task',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'cost',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /**
     * @return BelongsTo<AiModelRun, $this>
     */
    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(AiModelRun::class, 'model_run_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

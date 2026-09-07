<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.6: ai_prediction_candidates.
 *
 * Alternatif jawaban beserta confidence-nya (plan.md §14.1). Disimpan sebagai baris, bukan
 * JSON, supaya dapat diagregasi untuk metrik akurasi §32.2: seberapa sering jawaban yang
 * benar sebenarnya ada di peringkat kedua adalah pertanyaan yang menentukan apakah
 * classifier perlu diganti atau hanya perlu ambang yang berbeda.
 *
 * @property string $id
 * @property string $prediction_id
 * @property string $business_id
 * @property string $candidate_value
 * @property string $confidence
 * @property int $rank
 * @property Carbon $created_at
 */
class AiPredictionCandidate extends Model
{
    use BelongsToBusiness, HasUuids;

    /**
     * Append-only, jadi tidak ada kolom updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'prediction_id',
        'business_id',
        'candidate_value',
        'confidence',
        'rank',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AiPrediction, $this>
     */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(AiPrediction::class, 'prediction_id');
    }
}

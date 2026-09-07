<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Ai\Enums\AiPredictionStatus;
use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23.7: economic_event_predictions.
 *
 * @property string $id
 * @property string $business_id
 * @property string $transaction_id
 * @property string $event_type_id
 * @property string|null $prediction_id
 * @property string $confidence
 * @property string|null $reason
 * @property array<int, string>|null $missing_information
 * @property string $source
 * @property AiPredictionStatus $status
 * @property Carbon|null $applied_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EconomicEventPrediction extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'transaction_id',
        'event_type_id',
        'prediction_id',
        'confidence',
        'reason',
        'missing_information',
        'source',
        'status',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'missing_information' => 'array',
            'status' => AiPredictionStatus::class,
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $prediction): void {
            $mutable = ['status', 'applied_at', 'updated_at'];
            $changed = array_keys($prediction->getDirty());

            if (array_diff($changed, $mutable) !== []) {
                throw new RuntimeException(
                    'Prediksi economic event tidak dapat diubah selain statusnya (plan.md §31).'
                );
            }
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Prediksi economic event tidak dapat dihapus (plan.md §31).');
        });
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<EconomicEventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EconomicEventType::class, 'event_type_id');
    }

    /**
     * @return BelongsTo<AiPrediction, $this>
     */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(AiPrediction::class, 'prediction_id');
    }

    public function markSuperseded(): void
    {
        if ($this->status === AiPredictionStatus::Superseded) {
            return;
        }

        $this->status = AiPredictionStatus::Superseded;
        $this->save();
    }
}

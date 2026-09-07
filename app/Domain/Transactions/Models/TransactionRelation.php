<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Enums\TransactionRelationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23.5: transaction_relations.
 *
 * @property string $id
 * @property string $business_id
 * @property string $from_transaction_id
 * @property string $to_transaction_id
 * @property TransactionRelationType $type
 * @property string $confidence
 * @property array<int, string>|null $reasons
 * @property Carbon $created_at
 */
class TransactionRelation extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'from_transaction_id',
        'to_transaction_id',
        'type',
        'confidence',
        'reasons',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionRelationType::class,
            'reasons' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Hubungan transaksi tidak dapat diubah (plan.md §31).');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Hubungan transaksi tidak dapat dihapus (plan.md §31).');
        });
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function fromTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'from_transaction_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function toTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'to_transaction_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.5: transaction_tags.
 *
 * @property string $id
 * @property string $business_id
 * @property string $transaction_id
 * @property string $tag
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class TransactionTag extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'transaction_id',
        'tag',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

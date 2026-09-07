<?php

declare(strict_types=1);

namespace App\Domain\Entities\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Enums\EntityType;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.4 dan §9.2: entities.
 *
 * @property string $id
 * @property string $business_id
 * @property EntityType $type
 * @property string $name
 * @property string $normalized_name
 * @property EntityStatus $status
 * @property string|null $merged_into_id
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Entity extends Model implements KeepsAuditSnapshot
{
    use BelongsToBusiness, HasUuids, RecordsAuditTrail;

    protected $fillable = [
        'business_id',
        'type',
        'name',
        'normalized_name',
        'status',
        'merged_into_id',
        'confirmed_at',
        'confirmed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
            'status' => EntityStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EntityAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(EntityAlias::class);
    }

    /**
     * @return HasMany<EntityIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(EntityIdentifier::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'counterparty_entity_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isActive(): bool
    {
        return $this->status === EntityStatus::Active;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}

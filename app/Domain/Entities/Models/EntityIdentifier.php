<?php

declare(strict_types=1);

namespace App\Domain\Entities\Models;

use App\Domain\Entities\Enums\EntityIdentifierKind;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.4: entity_identifiers.
 *
 * @property string $id
 * @property string $business_id
 * @property string $entity_id
 * @property EntityIdentifierKind $kind
 * @property string $value
 * @property string $normalized_value
 * @property Carbon $created_at
 */
class EntityIdentifier extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'entity_id',
        'kind',
        'value',
        'normalized_value',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EntityIdentifierKind::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}

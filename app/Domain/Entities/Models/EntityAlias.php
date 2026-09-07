<?php

declare(strict_types=1);

namespace App\Domain\Entities\Models;

use App\Domain\Entities\Enums\EntityAliasSource;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.4: entity_aliases.
 *
 * @property string $id
 * @property string $business_id
 * @property string $entity_id
 * @property string $alias
 * @property string $normalized_alias
 * @property EntityAliasSource $source
 * @property Carbon $created_at
 */
class EntityAlias extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'entity_id',
        'alias',
        'normalized_alias',
        'source',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => EntityAliasSource::class,
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

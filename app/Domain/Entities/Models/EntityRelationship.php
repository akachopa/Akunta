<?php

declare(strict_types=1);

namespace App\Domain\Entities\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23.4: entity_relationships.
 *
 * Append-only. Jejak merge tidak boleh dihapus (plan.md §31, acceptance Phase 6).
 *
 * @property string $id
 * @property string $business_id
 * @property string $from_entity_id
 * @property string $to_entity_id
 * @property string $type
 * @property string|null $note
 * @property Carbon $created_at
 */
class EntityRelationship extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'from_entity_id',
        'to_entity_id',
        'type',
        'note',
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

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Jejak hubungan entity tidak dapat diubah (plan.md §31).');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Jejak hubungan entity tidak dapat dihapus (plan.md §31).');
        });
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function fromEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'from_entity_id');
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function toEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'to_entity_id');
    }
}

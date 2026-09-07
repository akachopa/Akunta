<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Business\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * plan.md §23.12 dan §31: audit_logs.
 *
 * Append-only. plan.md §44.7 melarang penghapusan audit history, sehingga update dan
 * delete ditolak di level model.
 *
 * Model ini sengaja tidak memakai global scope tenant: audit trail lintas tenant
 * dibutuhkan platform admin, dan pembacaannya dibatasi lewat policy.
 *
 * @property string $id
 * @property string|null $business_id
 * @property string $action
 * @property string $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'organization_id',
        'actor_id',
        'actor_label',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'reason',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Audit log bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Audit log bersifat append-only dan tidak dapat dihapus.');
        });
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

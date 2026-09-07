<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Domain\Business\Enums\PermissionSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * plan.md §23.1: permissions.
 *
 * @property string $id
 * @property PermissionSlug $slug
 */
class Permission extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'group',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => PermissionSlug::class,
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * plan.md §23.1 / §4: roles.
 *
 * @property string $id
 * @property RoleSlug $slug
 * @property string $name
 */
class Role extends Model
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'scope',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => RoleSlug::class,
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function hasPermission(PermissionSlug $permission): bool
    {
        return $this->permissions
            ->contains(fn (Permission $granted): bool => $granted->slug === $permission);
    }

    public static function findBySlug(RoleSlug $slug): self
    {
        return self::with('permissions')->where('slug', $slug->value)->firstOrFail();
    }
}

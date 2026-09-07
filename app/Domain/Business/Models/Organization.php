<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * plan.md §23.1: organizations.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $type
 */
class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const TYPE_BUSINESS = 'business';

    /**
     * Organization milik KJA/akuntan yang menaungi banyak client (plan.md §3.1).
     */
    public const TYPE_ACCOUNTING_FIRM = 'accounting_firm';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'owner_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Business, $this>
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot(['role_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function isAccountingFirm(): bool
    {
        return $this->type === self::TYPE_ACCOUNTING_FIRM;
    }
}

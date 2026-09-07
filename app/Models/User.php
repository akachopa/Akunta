<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessUser;
use App\Domain\Business\Models\Organization;
use App\Domain\Business\Models\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $is_platform_admin
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_platform_admin',
        'locale',
        'timezone',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot(['role_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * plan.md §3.1: satu user dapat memiliki beberapa bisnis, dan seorang akuntan dapat
     * terhubung ke beberapa client.
     *
     * @return BelongsToMany<Business, $this>
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_users')
            ->withPivot(['role_id', 'is_external', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<BusinessUser, $this>
     */
    public function businessMemberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function membershipFor(Business|string $business): ?BusinessUser
    {
        $businessId = $business instanceof Business ? $business->getKey() : $business;

        return $this->businessMemberships()
            ->with('role.permissions')
            ->where('business_id', $businessId)
            ->first();
    }

    public function roleIn(Business|string $business): ?Role
    {
        return $this->membershipFor($business)?->role;
    }

    public function belongsToBusiness(Business|string $business): bool
    {
        return $this->membershipFor($business) !== null;
    }

    /**
     * Pemeriksaan permission per business.
     *
     * Platform admin (plan.md §4.1) dikecualikan karena tugasnya lintas tenant, tetapi
     * pengecualian itu tetap harus melewati fungsi ini agar semua jalur otorisasi
     * terpusat di satu tempat.
     */
    public function hasBusinessPermission(Business|string $business, PermissionSlug $permission): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        $membership = $this->membershipFor($business);

        if ($membership === null) {
            return false;
        }

        return $membership->role->hasPermission($permission);
    }
}

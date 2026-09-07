<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.1: business_users.
 *
 * `is_external` menandai akuntan/KJA di luar organisasi bisnis (plan.md §2.1
 * "akuntan eksternal/KJA sebagai reviewer").
 *
 * Model ini tidak memakai BelongsToBusiness karena keanggotaan justru dipakai untuk
 * menentukan tenant mana yang boleh diakses; men-scope-nya dengan tenant aktif akan
 * membuat resolusi tenant menjadi sirkuler.
 *
 * @property string $id
 * @property string $business_id
 * @property string $user_id
 * @property string $role_id
 * @property bool $is_external
 * @property Role $role
 */
class BusinessUser extends Model
{
    use HasUuids;

    protected $table = 'business_users';

    protected $fillable = [
        'business_id',
        'user_id',
        'role_id',
        'is_external',
        'invited_at',
        'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessUser;
use App\Domain\Business\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengelolaan member business workspace (plan.md §37 Phase 1 "members", "roles").
 */
class MembershipService
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Melekatkan user ke sebuah business dengan role tertentu.
     *
     * `isExternal` menandai akuntan/KJA dari luar organisasi bisnis; keanggotaan inilah
     * yang memungkinkan satu akuntan mengakses beberapa client (plan.md §3.1).
     */
    public function attach(
        Business $business,
        User $user,
        RoleSlug $roleSlug,
        bool $isExternal = false,
        ?User $actor = null,
    ): BusinessUser {
        if (! $roleSlug->isBusinessScoped()) {
            throw new RuntimeException(
                "Role [{$roleSlug->value}] tidak dapat dilekatkan ke business workspace."
            );
        }

        return DB::transaction(function () use ($business, $user, $roleSlug, $isExternal, $actor): BusinessUser {
            $role = Role::findBySlug($roleSlug);

            $membership = BusinessUser::query()
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->first();

            if ($membership !== null) {
                return $this->changeRole($membership, $roleSlug, $actor);
            }

            $membership = BusinessUser::create([
                'business_id' => $business->getKey(),
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'is_external' => $isExternal,
                'invited_at' => now(),
                'joined_at' => now(),
            ]);

            $this->auditLogger->log('business_member.attached', $membership, null, [
                'user_id' => $user->getKey(),
                'user_email' => $user->email,
                'role' => $roleSlug->value,
                'is_external' => $isExternal,
            ], business: $business, actor: $actor);

            return $membership;
        });
    }

    public function changeRole(BusinessUser $membership, RoleSlug $roleSlug, ?User $actor = null): BusinessUser
    {
        return DB::transaction(function () use ($membership, $roleSlug, $actor): BusinessUser {
            $role = Role::findBySlug($roleSlug);
            $previous = $membership->role?->slug->value;

            $membership->update(['role_id' => $role->getKey()]);

            $this->auditLogger->log(
                'business_member.role_changed',
                $membership,
                ['role' => $previous],
                ['role' => $roleSlug->value],
                business: $membership->business,
                actor: $actor
            );

            return $membership->refresh();
        });
    }

    /**
     * Melepas member dari business.
     *
     * Owner terakhir tidak boleh dilepas agar workspace tidak kehilangan seluruh
     * pemegang izin business.manage.
     */
    public function detach(BusinessUser $membership, ?User $actor = null): void
    {
        DB::transaction(function () use ($membership, $actor): void {
            $ownerRoleId = Role::findBySlug(RoleSlug::BusinessOwner)->getKey();

            if ($membership->role_id === $ownerRoleId) {
                $remainingOwners = BusinessUser::query()
                    ->where('business_id', $membership->business_id)
                    ->where('role_id', $ownerRoleId)
                    ->whereKeyNot($membership->getKey())
                    ->count();

                if ($remainingOwners === 0) {
                    throw new RuntimeException('Business harus memiliki minimal satu business owner.');
                }
            }

            $snapshot = [
                'user_id' => $membership->user_id,
                'role' => $membership->role?->slug->value,
            ];
            $business = $membership->business;

            $membership->delete();

            $this->auditLogger->log(
                'business_member.detached',
                $membership,
                $snapshot,
                null,
                business: $business,
                actor: $actor
            );
        });
    }
}

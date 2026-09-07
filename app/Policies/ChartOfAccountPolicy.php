<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Models\User;

class ChartOfAccountPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::AccountView);
    }

    public function view(User $user, ChartOfAccount $account): bool
    {
        return $user->hasBusinessPermission($account->business_id, PermissionSlug::AccountView);
    }

    public function create(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::AccountManage);
    }

    public function update(User $user, ChartOfAccount $account): bool
    {
        return $user->hasBusinessPermission($account->business_id, PermissionSlug::AccountManage);
    }

    public function delete(User $user, ChartOfAccount $account): bool
    {
        return $user->hasBusinessPermission($account->business_id, PermissionSlug::AccountManage);
    }
}

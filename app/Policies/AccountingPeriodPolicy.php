<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Models\User;

class AccountingPeriodPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::PeriodView);
    }

    public function view(User $user, AccountingPeriod $period): bool
    {
        return $user->hasBusinessPermission($period->business_id, PermissionSlug::PeriodView);
    }

    public function close(User $user, AccountingPeriod $period): bool
    {
        return $user->hasBusinessPermission($period->business_id, PermissionSlug::PeriodClose);
    }

    /**
     * plan.md §37 Phase 13: "reopen requires permission". Pada plan.md §4 hanya
     * accountant/reviewer yang memiliki hak lock/reopen period.
     */
    public function reopen(User $user, AccountingPeriod $period): bool
    {
        return $user->hasBusinessPermission($period->business_id, PermissionSlug::PeriodReopen);
    }
}

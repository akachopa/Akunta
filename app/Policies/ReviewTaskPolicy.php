<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Review\Models\ReviewTask;
use App\Models\User;

class ReviewTaskPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::TransactionView)
            || $user->hasBusinessPermission($business, PermissionSlug::DocumentReview);
    }

    public function view(User $user, ReviewTask $task): bool
    {
        return $user->hasBusinessPermission($task->business_id, PermissionSlug::TransactionView)
            || $user->hasBusinessPermission($task->business_id, PermissionSlug::DocumentReview);
    }

    public function review(User $user, ReviewTask $task): bool
    {
        return $user->hasBusinessPermission($task->business_id, PermissionSlug::TransactionReview)
            || $user->hasBusinessPermission($task->business_id, PermissionSlug::DocumentReview);
    }

    public function approve(User $user, ReviewTask $task): bool
    {
        return $user->hasBusinessPermission($task->business_id, PermissionSlug::TransactionApprove);
    }
}

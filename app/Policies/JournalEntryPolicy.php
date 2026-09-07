<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::JournalView);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->hasBusinessPermission($entry->business_id, PermissionSlug::JournalView);
    }

    public function create(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::JournalCreate);
    }

    /**
     * Posted journal tidak dapat diubah oleh siapa pun (plan.md §44.6), sehingga
     * permission saja tidak cukup.
     */
    public function update(User $user, JournalEntry $entry): bool
    {
        return $entry->status->isEditable()
            && $user->hasBusinessPermission($entry->business_id, PermissionSlug::JournalCreate);
    }

    public function approve(User $user, JournalEntry $entry): bool
    {
        return $user->hasBusinessPermission($entry->business_id, PermissionSlug::JournalApprove);
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->hasBusinessPermission($entry->business_id, PermissionSlug::JournalPost);
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->hasBusinessPermission($entry->business_id, PermissionSlug::JournalReverse);
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return $entry->status->isEditable()
            && $user->hasBusinessPermission($entry->business_id, PermissionSlug::JournalCreate);
    }
}

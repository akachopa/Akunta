<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Models\User;

/**
 * plan.md §30 "authorization policies" dan §44.15 "jangan mengizinkan cross-tenant
 * access".
 *
 * Semua policy pada Phase 1–2 bertumpu pada satu pemeriksaan: apakah user punya
 * permission tersebut di business ini. Karena membership adalah satu-satunya jalan
 * memperoleh permission, user tanpa membership otomatis tertolak.
 */
class BusinessPolicy
{
    public function view(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::BusinessView);
    }

    public function update(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::BusinessManage);
    }

    public function viewMembers(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::MemberView);
    }

    public function manageMembers(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::MemberManage);
    }

    public function viewBankAccounts(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::BankAccountView);
    }

    public function manageBankAccounts(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::BankAccountManage);
    }

    public function viewReports(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::ReportView);
    }

    public function viewAuditTrail(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::AuditView);
    }
}

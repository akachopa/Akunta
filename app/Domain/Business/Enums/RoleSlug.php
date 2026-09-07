<?php

declare(strict_types=1);

namespace App\Domain\Business\Enums;

/**
 * Role sesuai plan.md §4.
 */
enum RoleSlug: string
{
    case PlatformAdmin = 'platform_admin';
    case BusinessOwner = 'business_owner';
    case BusinessStaff = 'business_staff';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform Admin',
            self::BusinessOwner => 'Business Owner',
            self::BusinessStaff => 'Business Staff',
            self::Accountant => 'Accountant / Reviewer',
        };
    }

    /**
     * Role yang dapat dilekatkan ke sebuah business workspace.
     */
    public function isBusinessScoped(): bool
    {
        return $this !== self::PlatformAdmin;
    }

    /**
     * Permission per role, dibatasi pada kapabilitas yang sudah ada di Phase 0–2.
     *
     * plan.md §4.2 menyatakan owner dapat "initiate closing" tetapi tidak menyebut
     * reopen; plan.md §4.4 memberikan "lock/reopen period" kepada accountant. Pembagian
     * di bawah mengikuti pembagian tersebut secara literal.
     *
     * @return array<int, PermissionSlug>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformAdmin => PermissionSlug::cases(),

            self::BusinessOwner => [
                PermissionSlug::BusinessView,
                PermissionSlug::BusinessManage,
                PermissionSlug::MemberView,
                PermissionSlug::MemberManage,
                PermissionSlug::BankAccountView,
                PermissionSlug::BankAccountManage,
                PermissionSlug::PeriodView,
                PermissionSlug::PeriodClose,
                PermissionSlug::AccountView,
                PermissionSlug::AccountManage,
                PermissionSlug::JournalView,
                PermissionSlug::JournalCreate,
                PermissionSlug::JournalApprove,
                PermissionSlug::ReportView,
                PermissionSlug::AuditView,
            ],

            self::BusinessStaff => [
                PermissionSlug::BusinessView,
                PermissionSlug::MemberView,
                PermissionSlug::BankAccountView,
                PermissionSlug::PeriodView,
                PermissionSlug::AccountView,
                PermissionSlug::JournalView,
                PermissionSlug::JournalCreate,
            ],

            self::Accountant => [
                PermissionSlug::BusinessView,
                PermissionSlug::MemberView,
                PermissionSlug::BankAccountView,
                PermissionSlug::BankAccountManage,
                PermissionSlug::PeriodView,
                PermissionSlug::PeriodClose,
                PermissionSlug::PeriodReopen,
                PermissionSlug::AccountView,
                PermissionSlug::AccountManage,
                PermissionSlug::JournalView,
                PermissionSlug::JournalCreate,
                PermissionSlug::JournalApprove,
                PermissionSlug::JournalPost,
                PermissionSlug::JournalReverse,
                PermissionSlug::ReportView,
                PermissionSlug::AuditView,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

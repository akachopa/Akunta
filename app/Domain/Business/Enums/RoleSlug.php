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
     * Permission per role, dibatasi pada kapabilitas yang sudah ada di Phase 0–10.
     *
     * plan.md §4.2 menyatakan owner dapat "initiate closing" tetapi tidak menyebut
     * reopen; plan.md §4.4 memberikan "lock/reopen period" kepada accountant. Pembagian
     * di bawah mengikuti pembagian tersebut secara literal.
     *
     * Untuk dokumen, plan.md §4.2 dan §4.3 memberi owner dan staff hak "upload data"
     * serta melihat dokumen. Reprocess dan archive tidak disebut untuk staff, sehingga
     * keduanya hanya diberikan kepada owner dan accountant. Accountant mendapat hak
     * upload karena rekonsiliasi pada plan.md §4.4 menuntut mutasi bank klien berada di
     * dalam sistem.
     *
     * Review dokumen diberikan kepada owner dan accountant. plan.md §4.2 memberi owner
     * hak "approve transaksi" dan §4.4 memberi accountant hak "review & koreksi", jadi
     * keduanya memang menyatakan kebenaran data. Staff tidak: §4.3 membatasinya pada input
     * dan melihat, dan koreksi field adalah pernyataan yang menjadi dasar jurnal.
     *
     * Melihat transaksi diberikan kepada ketiga role bisnis. Transaksi adalah data yang
     * mereka masukkan sendiri lewat dokumen, dan staff yang tidak dapat melihatnya tidak
     * dapat mengetahui bahwa unggahannya sudah terbaca dengan benar (plan.md §4.3).
     *
     * Approve transaksi: plan.md §4.2 memberi owner hak "approve transaksi", §4.4 memberi
     * accountant hak "review & koreksi". Staff tidak: §4.3 membatasinya pada input dan
     * melihat.
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
                PermissionSlug::DocumentView,
                PermissionSlug::DocumentUpload,
                PermissionSlug::DocumentManage,
                PermissionSlug::DocumentReview,
                PermissionSlug::TransactionView,
                PermissionSlug::TransactionReview,
                PermissionSlug::TransactionApprove,
                PermissionSlug::EntityView,
                PermissionSlug::EntityManage,
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
                PermissionSlug::DocumentView,
                PermissionSlug::DocumentUpload,
                PermissionSlug::TransactionView,
                PermissionSlug::EntityView,
                PermissionSlug::BankAccountView,
                PermissionSlug::PeriodView,
                PermissionSlug::AccountView,
                PermissionSlug::JournalView,
                PermissionSlug::JournalCreate,
            ],

            self::Accountant => [
                PermissionSlug::BusinessView,
                PermissionSlug::MemberView,
                PermissionSlug::DocumentView,
                PermissionSlug::DocumentUpload,
                PermissionSlug::DocumentManage,
                PermissionSlug::DocumentReview,
                PermissionSlug::TransactionView,
                PermissionSlug::TransactionReview,
                PermissionSlug::TransactionApprove,
                PermissionSlug::EntityView,
                PermissionSlug::EntityManage,
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

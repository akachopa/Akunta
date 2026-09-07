<?php

declare(strict_types=1);

namespace App\Domain\Business\Enums;

/**
 * Permission granular yang dipakai policy.
 *
 * Daftar ini sengaja hanya memuat kapabilitas yang benar-benar ada di Phase 0–5.
 * Permission untuk rekonsiliasi dan closing ditambahkan ketika phase-nya dikerjakan, agar
 * tidak ada permission menggantung tanpa penegakan.
 */
enum PermissionSlug: string
{
    case BusinessView = 'business.view';
    case BusinessManage = 'business.manage';

    case MemberView = 'member.view';
    case MemberManage = 'member.manage';

    case DocumentView = 'document.view';
    case DocumentUpload = 'document.upload';
    case DocumentManage = 'document.manage';

    /*
     * Dipisahkan dari `document.manage` karena akibatnya berbeda. Reprocess dan archive
     * hanya memindahkan dokumen di dalam pipeline; review menetapkan nilai yang akan
     * menjadi transaksi, dan pernyataannya tercatat sebagai keputusan manusia
     * (plan.md §16, §17.1).
     */
    case DocumentReview = 'document.review';

    /*
     * Transaksi canonical hasil normalisasi (plan.md §37 Phase 5). Baru hak melihat yang
     * ada: Phase 5 tidak memiliki tindakan user atas transaksi. Approve dan reject pada
     * plan.md §29.4 ditambahkan bersama review center Phase 10 yang menegakkannya.
     */
    case TransactionView = 'transaction.view';

    case BankAccountView = 'bank_account.view';
    case BankAccountManage = 'bank_account.manage';

    case PeriodView = 'period.view';
    case PeriodClose = 'period.close';
    case PeriodReopen = 'period.reopen';

    case AccountView = 'account.view';
    case AccountManage = 'account.manage';

    case JournalView = 'journal.view';
    case JournalCreate = 'journal.create';
    case JournalApprove = 'journal.approve';
    case JournalPost = 'journal.post';
    case JournalReverse = 'journal.reverse';

    case ReportView = 'report.view';

    case AuditView = 'audit.view';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function description(): string
    {
        return match ($this) {
            self::BusinessView => 'Melihat workspace bisnis',
            self::BusinessManage => 'Mengelola profil dan pengaturan bisnis',
            self::MemberView => 'Melihat daftar member',
            self::MemberManage => 'Menambah, mengubah, dan menghapus member',
            self::DocumentView => 'Melihat dokumen pada inbox',
            self::DocumentUpload => 'Mengunggah dokumen ke inbox',
            self::DocumentManage => 'Memproses ulang dan mengarsipkan dokumen',
            self::DocumentReview => 'Meninjau dan mengoreksi hasil pembacaan dokumen',
            self::TransactionView => 'Melihat transaksi hasil normalisasi dokumen',
            self::BankAccountView => 'Melihat rekening bank',
            self::BankAccountManage => 'Mengelola rekening bank',
            self::PeriodView => 'Melihat accounting period',
            self::PeriodClose => 'Menutup accounting period',
            self::PeriodReopen => 'Membuka kembali accounting period yang sudah ditutup',
            self::AccountView => 'Melihat chart of accounts',
            self::AccountManage => 'Mengelola chart of accounts',
            self::JournalView => 'Melihat journal entry',
            self::JournalCreate => 'Membuat dan mengubah journal entry draft',
            self::JournalApprove => 'Menyetujui journal entry',
            self::JournalPost => 'Memposting journal entry ke ledger',
            self::JournalReverse => 'Membuat reversal journal entry',
            self::ReportView => 'Melihat laporan akuntansi',
            self::AuditView => 'Melihat audit trail',
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

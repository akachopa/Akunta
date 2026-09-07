<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

/**
 * Hak atas transaksi hasil normalisasi (plan.md §29.4, Review Center Phase 10).
 *
 * Melihat: semua role bisnis.
 * Review (koreksi, komentar, tag): owner dan accountant (plan.md §4.2, §4.4).
 * Approve/reject: owner dan accountant.
 * Posting jurnal tetap memakai JournalEntryPolicy::post (hanya accountant).
 */
class TransactionPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::TransactionView);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->hasBusinessPermission($transaction->business_id, PermissionSlug::TransactionView);
    }

    public function review(User $user, Transaction $transaction): bool
    {
        return $user->hasBusinessPermission($transaction->business_id, PermissionSlug::TransactionReview);
    }

    public function approve(User $user, Transaction $transaction): bool
    {
        return $user->hasBusinessPermission($transaction->business_id, PermissionSlug::TransactionApprove);
    }

    public function reject(User $user, Transaction $transaction): bool
    {
        return $this->approve($user, $transaction);
    }

    /**
     * plan.md §44.7: transaksi adalah dasar jurnal, dan jejaknya tidak boleh dihapus.
     *
     * Transaksi yang keliru ditolak lewat status REJECTED, bukan dihapus. Satu-satunya
     * penghapusan yang terjadi di sistem adalah penggantian oleh normalizer atas transaksi
     * yang belum diputuskan siapa pun, dan itu bukan tindakan user.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        return false;
    }
}

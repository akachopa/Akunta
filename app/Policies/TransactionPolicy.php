<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

/**
 * Hak atas transaksi hasil normalisasi (plan.md §29.4).
 *
 * Pada Phase 5 hanya hak melihat yang ada. Approve, reject, dan koreksi transaksi memang
 * disebut plan.md §29.4, tetapi ketiganya menetapkan makna ekonomi yang baru ditafsirkan
 * Phase 8 dan disetujui lewat review center Phase 10. Menyediakan permission-nya lebih awal
 * berarti menegakkan hak atas tindakan yang belum ada, dan itu tidak dapat diuji.
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

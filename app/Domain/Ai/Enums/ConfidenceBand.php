<?php

declare(strict_types=1);

namespace App\Domain\Ai\Enums;

use App\Domain\Documents\Enums\DocumentStatus;

/**
 * Pita keputusan confidence engine (plan.md §15.2).
 *
 * >= auto_ready        → READY, kandidat proses otomatis
 * >= review_threshold  → REVIEW RECOMMENDED
 * < review_threshold   → HUMAN CONFIRMATION REQUIRED
 *
 * Pada Phase 4 hanya pita `Ready` yang membawa dokumen ke status READY. Dua pita lainnya
 * mengarah ke NEED_REVIEW, termasuk `ReviewRecommended`. Itu keputusan sadar: plan.md §16.1
 * menerapkan exception-based accounting, sehingga apa pun yang layak diperiksa harus muncul
 * di antrean reviewer, dan plan.md §32.2 menetapkan false auto approval harus sangat rendah.
 * "Disarankan diperiksa" tetapi lolos tanpa diperiksa adalah kombinasi yang tidak berguna.
 */
enum ConfidenceBand: string
{
    case Ready = 'ready';
    case ReviewRecommended = 'review_recommended';
    case HumanConfirmationRequired = 'human_confirmation_required';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Siap Diproses',
            self::ReviewRecommended => 'Disarankan Diperiksa',
            self::HumanConfirmationRequired => 'Perlu Konfirmasi Manusia',
        };
    }

    /**
     * plan.md §44.8 melarang auto-approve untuk confidence rendah.
     */
    public function allowsAutomaticProcessing(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Status dokumen berikutnya setelah datanya selesai dibaca atau dikoreksi.
     *
     * Sejak Phase 5, pita `Ready` tidak lagi berarti dokumen selesai: datanya cukup dapat
     * dipercaya untuk dinormalisasi menjadi transaksi, dan READY baru diberikan setelah
     * transaksinya benar-benar terbentuk. Dua pita lainnya tetap berhenti di NEED_REVIEW —
     * data yang belum dipastikan tidak boleh menjadi transaksi (plan.md §44.8).
     */
    public function nextDocumentStatus(): DocumentStatus
    {
        return $this->allowsAutomaticProcessing()
            ? DocumentStatus::Normalizing
            : DocumentStatus::NeedReview;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Enums;

/**
 * State machine transaksi (plan.md §25.2).
 *
 * DETECTED → NORMALIZED → MATCHING → CLASSIFIED → READY → APPROVED → POSTED, dengan
 * NEED_REVIEW dan REJECTED sebagai cabang.
 *
 * Pada Phase 5 transaksi lahir langsung sebagai NORMALIZED: normalizer-lah yang
 * menciptakannya, sehingga tidak ada saat di mana ia sudah ada tetapi belum dinormalisasi.
 * DETECTED tetap didefinisikan karena plan.md §25.2 menyebutnya dan karena sumber masa
 * depan — bank API feed, integrasi marketplace — memang mendeteksi baris lebih dulu lalu
 * menormalisasinya kemudian.
 *
 * NEED_REVIEW dipakai ketika angka dan tanggalnya terbaca pasti tetapi arah aliran nilainya
 * tidak dapat ditentukan tanpa penafsiran, misalnya bukti transfer yang tidak menyatakan
 * siapa pengirimnya. Menebak arahnya akan menempatkan uang di sisi yang salah, dan itu
 * kesalahan yang lebih mahal daripada satu baris antrean review.
 */
enum TransactionStatus: string
{
    case Detected = 'detected';
    case Normalized = 'normalized';
    case Matching = 'matching';
    case Classified = 'classified';
    case NeedReview = 'need_review';
    case Ready = 'ready';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Terdeteksi',
            self::Normalized => 'Ternormalisasi',
            self::Matching => 'Pencocokan',
            self::Classified => 'Terklasifikasi',
            self::NeedReview => 'Perlu Informasi',
            self::Ready => 'Siap',
            self::Approved => 'Disetujui',
            self::Posted => 'Terposting',
            self::Rejected => 'Ditolak',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Detected => [self::Normalized, self::NeedReview, self::Rejected],
            self::Normalized => [self::Matching, self::Classified, self::NeedReview, self::Rejected],
            self::Matching => [self::Classified, self::NeedReview, self::Rejected],
            self::Classified => [self::Ready, self::NeedReview, self::Rejected],
            self::NeedReview => [self::Normalized, self::Classified, self::Ready, self::Rejected],
            self::Ready => [self::Approved, self::NeedReview, self::Rejected],
            self::Approved => [self::Posted, self::NeedReview, self::Rejected],

            // Transaksi yang sudah masuk ledger hanya dapat dikoreksi lewat reversal
            // journal (plan.md §44.6), bukan dengan mengubah transaksinya.
            self::Posted => [],
            self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Transaksi yang boleh dibuat ulang oleh normalizer.
     *
     * Dokumen dapat dinormalisasi ulang — reviewer mengoreksi angkanya, atau dokumen
     * diproses ulang — dan hasil lama harus digantikan agar tidak ada dua versi transaksi
     * dari satu baris sumber. Tiga status dikecualikan karena pada ketiganya sudah ada
     * keputusan yang tidak boleh dibatalkan diam-diam oleh sebuah job: manusia menyetujui,
     * ledger mencatat, atau manusia menolak (plan.md §31, §44.7).
     */
    public function isRegenerable(): bool
    {
        return match ($this) {
            self::Approved, self::Posted, self::Rejected => false,
            default => true,
        };
    }

    /**
     * Menunggu keputusan manusia (plan.md §16.2 review queue).
     */
    public function needsAttention(): bool
    {
        return $this === self::NeedReview;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

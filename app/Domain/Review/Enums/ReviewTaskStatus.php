<?php

declare(strict_types=1);

namespace App\Domain\Review\Enums;

/**
 * Status tugas di Review Center (plan.md §16.2).
 *
 * OPEN: menunggu approve/reject/koreksi.
 * AWAITING_POST: transaksi disetujui, jurnal masih draft — posting menunggu akuntan
 * (plan.md §15.2).
 * COMPLETED: diposting atau ditolak.
 * CANCELLED: tidak lagi perlu manusia (misalnya dokumen diproses ulang).
 */
enum ReviewTaskStatus: string
{
    case Open = 'open';
    case AwaitingPost = 'awaiting_post';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Menunggu',
            self::AwaitingPost => 'Menunggu Posting',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Open, self::AwaitingPost => true,
            self::Completed, self::Cancelled => false,
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

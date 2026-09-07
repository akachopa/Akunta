<?php

declare(strict_types=1);

namespace App\Domain\Review\Enums;

/**
 * Alasan sebuah tugas masuk Review Center (plan.md §16.1 exception-based accounting).
 *
 * NEED_INFORMATION: manusia harus mengisi atau mengoreksi sebelum transaksi siap.
 * READY_FOR_APPROVAL: confidence lolos, tetapi posting tetap menunggu akuntan
 * (plan.md §15.2).
 * DUPLICATE: bukti tampak sama dengan transaksi lain; tidak dijurnal dua kali.
 */
enum ReviewTaskKind: string
{
    case NeedInformation = 'need_information';
    case ReadyForApproval = 'ready_for_approval';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::NeedInformation => 'Perlu Informasi',
            self::ReadyForApproval => 'Siap Disetujui',
            self::Duplicate => 'Duplikat',
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

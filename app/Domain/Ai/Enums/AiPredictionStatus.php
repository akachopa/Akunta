<?php

declare(strict_types=1);

namespace App\Domain\Ai\Enums;

/**
 * Status pemakaian satu prediksi.
 *
 * Prediksi tidak pernah dihapus ketika dikalahkan manusia; statusnya menjadi `Superseded`
 * dan barisnya tetap ada. plan.md §31 melarang keputusan historis ditimpa, dan tanpa
 * prediksi asli beserta confidence-nya, akurasi classifier pada plan.md §32.2 tidak dapat
 * diukur sama sekali.
 */
enum AiPredictionStatus: string
{
    case Applied = 'applied';
    case Superseded = 'superseded';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Dipakai',
            self::Superseded => 'Dikalahkan Manusia',
            self::Rejected => 'Ditolak Validasi',
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

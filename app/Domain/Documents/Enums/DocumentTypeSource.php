<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Asal penetapan jenis dokumen (plan.md §17.1).
 *
 * Pembedaan ini menentukan perilaku classifier: pernyataan user dan koreksi reviewer tidak
 * boleh ditimpa prediksi AI. Tanpa kolom ini, dokumen yang jenisnya sudah dipastikan
 * manusia akan berubah sendiri setiap kali diproses ulang.
 */
enum DocumentTypeSource: string
{
    case User = 'user';
    case Ai = 'ai';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Ditetapkan Pengunggah',
            self::Ai => 'Prediksi AI',
            self::Review => 'Dikonfirmasi Reviewer',
        };
    }

    /**
     * Ditetapkan manusia, sehingga classifier tidak boleh menggantinya.
     */
    public function isHuman(): bool
    {
        return $this !== self::Ai;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

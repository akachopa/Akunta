<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Asal nilai satu field.
 *
 * Nilai yang berasal dari reviewer diperlakukan berbeda oleh confidence engine: manusia
 * tidak menghasilkan confidence, ia menghasilkan kepastian. Tanpa pembedaan ini, dokumen
 * yang sudah dikoreksi manusia akan tetap tampak berisiko hanya karena confidence AI-nya
 * rendah.
 */
enum DocumentFieldSource: string
{
    case Ai = 'ai';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'Ekstraksi AI',
            self::Review => 'Koreksi Reviewer',
        };
    }

    public function isHuman(): bool
    {
        return $this === self::Review;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai\Enums;

/**
 * Hasil satu panggilan provider.
 *
 * `Rejected` dan `Failed` sengaja dipisahkan. Provider yang tidak dapat dihubungi layak
 * diretry, sedangkan provider yang merespons dengan output melanggar kontrak akan
 * mengulangi kesalahan yang sama. Menyatukan keduanya akan membuat queue meretry sesuatu
 * yang tidak akan pernah berhasil, dan metrik kegagalan kehilangan artinya.
 */
enum AiRunStatus: string
{
    case Succeeded = 'succeeded';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Berhasil',
            self::Rejected => 'Output Ditolak',
            self::Failed => 'Gagal',
        };
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Jenis berkas yang menempel pada satu dokumen (`document_files`).
 *
 * plan.md §37 Phase 3 acceptance mewajibkan "original file tetap tersimpan", sehingga
 * berkas `original` bersifat append-only: ia tidak boleh diubah maupun dihapus selama
 * dokumennya masih ada. Berkas turunan seperti thumbnail boleh dibuat ulang.
 */
enum DocumentFileKind: string
{
    case Original = 'original';
    case Thumbnail = 'thumbnail';

    public function isImmutable(): bool
    {
        return $this === self::Original;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

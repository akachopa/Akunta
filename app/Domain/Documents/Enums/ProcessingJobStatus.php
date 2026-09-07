<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Status satu percobaan tahap pipeline (`document_processing_jobs`).
 *
 * `pending` dipakai untuk tahap yang phase-nya belum dibangun: baris itu ada supaya
 * timeline pipeline menampilkan apa yang masih ditunggu, bukan menyembunyikannya.
 */
enum ProcessingJobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Phase Berikutnya',
            self::Queued => 'Dalam Antrean',
            self::Running => 'Berjalan',
            self::Succeeded => 'Selesai',
            self::Failed => 'Gagal',
            self::Skipped => 'Dilewati',
        };
    }

    public function isFinished(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Skipped => true,
            default => false,
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

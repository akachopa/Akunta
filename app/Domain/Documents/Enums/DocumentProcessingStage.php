<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Tahap pipeline dokumen (plan.md §13.1).
 *
 * Setiap tahap dicatat sebagai baris `document_processing_jobs` supaya durasi, jumlah
 * percobaan, dan penyebab kegagalannya terlihat (plan.md §32.1 `average_processing_time`,
 * `documents_failed`).
 *
 * Sampai Phase 4 tahap `parse`, `classify`, dan `extract` sudah berjalan. `normalize` dan
 * `match` tetap terdaftar di sini agar timeline pipeline dapat menampilkan apa yang masih
 * menunggu, dan `isImplemented()` menjaga agar tidak ada kode yang mengira tahap itu
 * berjalan.
 */
enum DocumentProcessingStage: string
{
    case Parse = 'parse';
    case Classify = 'classify';
    case Extract = 'extract';
    case Normalize = 'normalize';
    case Match = 'match';

    public function label(): string
    {
        return match ($this) {
            self::Parse => 'Membaca Berkas',
            self::Classify => 'Klasifikasi Dokumen',
            self::Extract => 'Ekstraksi Data',
            self::Normalize => 'Normalisasi Transaksi',
            self::Match => 'Pencocokan',
        };
    }

    /**
     * Phase tempat tahap ini dibangun, dipakai untuk menjelaskan ke user mengapa sebuah
     * tahap belum berjalan.
     */
    public function phase(): int
    {
        return match ($this) {
            self::Parse => 3,
            self::Classify, self::Extract => 4,
            self::Normalize => 5,
            self::Match => 7,
        };
    }

    public function isImplemented(): bool
    {
        return match ($this) {
            self::Parse, self::Classify, self::Extract => true,
            self::Normalize, self::Match => false,
        };
    }

    /**
     * Status dokumen selama tahap ini berjalan (plan.md §25.1).
     */
    public function runningStatus(): DocumentStatus
    {
        return match ($this) {
            self::Parse => DocumentStatus::Parsing,
            self::Classify => DocumentStatus::Classifying,
            self::Extract => DocumentStatus::Extracting,
            self::Normalize => DocumentStatus::Normalizing,
            self::Match => DocumentStatus::Matching,
        };
    }

    public function next(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $cases[$index + 1] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

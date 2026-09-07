<?php

declare(strict_types=1);

namespace App\Domain\Ai\Enums;

/**
 * Task inferensi yang dikenal aplikasi (plan.md §14).
 *
 * plan.md §44.1 melarang satu prompt besar untuk seluruh pipeline, jadi setiap task adalah
 * panggilan tersendiri dengan kontrak dan biayanya sendiri. Enum ini menjadi kunci
 * pencatatan pada `ai_model_runs` dan `ai_usage_logs`, sehingga biaya dan akurasi dapat
 * dipisahkan per task (plan.md §32.1, §32.2).
 */
enum AiTask: string
{
    case ClassifyDocument = 'classify_document';
    case ExtractDocument = 'extract_document';

    public function label(): string
    {
        return match ($this) {
            self::ClassifyDocument => 'Klasifikasi Dokumen',
            self::ExtractDocument => 'Ekstraksi Dokumen',
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

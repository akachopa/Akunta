<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tab inbox sesuai plan.md §6.
 *
 * Inbox
 * ├── All Documents
 * ├── Processing
 * ├── Need Information
 * ├── Failed
 * └── Archived
 *
 * plan.md §40 mewajibkan server-side filter, sehingga penyaringannya berada di sini dan
 * bukan di frontend.
 */
enum InboxFilter: string
{
    case All = 'all';
    case Processing = 'processing';
    case NeedInformation = 'need-information';
    case Failed = 'failed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Semua Dokumen',
            self::Processing => 'Diproses',
            self::NeedInformation => 'Perlu Informasi',
            self::Failed => 'Gagal',
            self::Archived => 'Diarsipkan',
        };
    }

    /**
     * @param  Builder<\App\Domain\Documents\Models\Document>  $query
     * @return Builder<\App\Domain\Documents\Models\Document>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            /*
             * Tab "All Documents" tetap mengecualikan arsip karena arsip punya tab
             * tersendiri; menampilkannya di kedua tab akan membuat jumlah dokumen yang
             * dilihat user tidak konsisten.
             */
            self::All => $query->notArchived(),

            self::Processing => $query->withStatus(
                DocumentStatus::Queued,
                DocumentStatus::Parsing,

                /*
                 * CLASSIFYING ikut ditampilkan sebagai "diproses" karena dari sudut
                 * pandang user dokumennya memang belum selesai. Selama Phase 3 dokumen
                 * berhenti di status ini menunggu classifier Phase 4.
                 */
                DocumentStatus::Classifying,

                DocumentStatus::Extracting,
                DocumentStatus::Normalizing,
                DocumentStatus::Matching,
            ),

            self::NeedInformation => $query->withStatus(DocumentStatus::NeedReview),

            // UNSUPPORTED ditampilkan bersama FAILED: keduanya menuntut tindakan user.
            self::Failed => $query->withStatus(DocumentStatus::Failed, DocumentStatus::Unsupported),

            self::Archived => $query->withStatus(DocumentStatus::Archived),
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

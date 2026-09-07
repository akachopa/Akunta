<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * State machine dokumen (plan.md §25.1) beserta UNSUPPORTED dari plan.md §5.3.
 *
 * UPLOADED → QUEUED → PARSING → CLASSIFYING → EXTRACTING → NORMALIZING → MATCHING →
 * READY, dengan NEED_REVIEW, UNSUPPORTED, FAILED, dan ARCHIVED sebagai cabang.
 *
 * Phase 3 hanya menjalankan pipeline sampai PARSING selesai. Dokumen kemudian berhenti
 * di CLASSIFYING karena classifier-nya adalah Phase 4 (plan.md §37). Berhenti di state
 * yang menyebut tahap berikutnya lebih jujur daripada menandai dokumen READY, karena
 * dokumen memang belum menghasilkan transaksi apa pun.
 */
enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Parsing = 'parsing';
    case Classifying = 'classifying';
    case Extracting = 'extracting';
    case Normalizing = 'normalizing';
    case Matching = 'matching';
    case Ready = 'ready';
    case NeedReview = 'need_review';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Terunggah',
            self::Queued => 'Menunggu Proses',
            self::Parsing => 'Membaca Berkas',
            self::Classifying => 'Menunggu Klasifikasi',
            self::Extracting => 'Mengekstrak Data',
            self::Normalizing => 'Normalisasi',
            self::Matching => 'Pencocokan',
            self::Ready => 'Siap',
            self::NeedReview => 'Perlu Informasi',
            self::Unsupported => 'Tipe Tidak Didukung',
            self::Failed => 'Gagal',
            self::Archived => 'Diarsipkan',
        };
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Uploaded => [self::Queued, self::Unsupported, self::Failed, self::Archived],
            self::Queued => [self::Parsing, self::Unsupported, self::Failed, self::Archived],
            /*
             * PARSING dapat kembali ke QUEUED agar percobaan yang terhenti, misalnya
             * karena worker mati di tengah jalan, dapat dipulihkan tanpa intervensi
             * database. ShouldBeUnique pada ProcessDocumentJob mencegah dokumen yang
             * sedang benar-benar diproses diantre ulang.
             */
            self::Parsing => [self::Classifying, self::NeedReview, self::Queued, self::Unsupported, self::Failed, self::Archived],

            /*
             * CLASSIFYING dapat kembali ke QUEUED karena plan.md §29.3 menyediakan
             * reprocess untuk dokumen apa pun, bukan hanya yang gagal. Selama Phase 3 ini
             * juga satu-satunya jalan memproses ulang dokumen yang sudah selesai diparse.
             */
            self::Classifying => [self::Extracting, self::NeedReview, self::Queued, self::Failed, self::Archived],

            self::Extracting => [self::Normalizing, self::NeedReview, self::Failed, self::Archived],
            self::Normalizing => [self::Matching, self::NeedReview, self::Failed, self::Archived],
            self::Matching => [self::Ready, self::NeedReview, self::Failed, self::Archived],

            // plan.md §37 Phase 3 acceptance: "failure dapat diretry". Retry mengembalikan
            // dokumen ke QUEUED tanpa menyentuh berkas aslinya.
            self::Ready => [self::NeedReview, self::Queued, self::Archived],
            self::NeedReview => [self::Queued, self::Ready, self::Archived],
            self::Unsupported => [self::Queued, self::Archived],
            self::Failed => [self::Queued, self::Archived],

            // Dokumen yang diarsipkan dapat dikeluarkan lagi dari arsip.
            self::Archived => [self::Queued],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Sedang berjalan di pipeline, sehingga UI perlu terus melakukan polling
     * (plan.md §37 Phase 3 acceptance "processing status real-time/polling").
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::Queued, self::Parsing, self::Extracting, self::Normalizing, self::Matching => true,

            /*
             * CLASSIFYING dihitung tidak berjalan selama Phase 3: tidak ada worker yang
             * akan mengubahnya, jadi polling tanpa akhir hanya membebani server. Phase 4
             * memindahkannya kembali menjadi status berjalan.
             */
            default => false,
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::Failed, self::Unsupported => true,
            default => false,
        };
    }

    /**
     * Dapat diproses ulang atas permintaan user (plan.md §29.3 reprocess).
     */
    public function isRetryable(): bool
    {
        return $this->canTransitionTo(self::Queued);
    }

    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Status yang menunggu keputusan manusia (plan.md §6 Inbox "Need Information").
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::NeedReview, self::Unsupported, self::Failed => true,
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

    /**
     * @return array<int, string>
     */
    public static function processingValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isProcessing())
        ));
    }
}

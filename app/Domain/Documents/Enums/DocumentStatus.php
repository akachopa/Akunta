<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * State machine dokumen (plan.md §25.1) beserta UNSUPPORTED dari plan.md §5.3.
 *
 * UPLOADED → QUEUED → PARSING → CLASSIFYING → EXTRACTING → NORMALIZING → MATCHING →
 * READY, dengan NEED_REVIEW, UNSUPPORTED, FAILED, dan ARCHIVED sebagai cabang.
 *
 * Sampai Phase 4 pipeline berjalan hingga EXTRACTING selesai. Dokumen yang ekstraksinya
 * diterima dan confidence-nya melewati ambang auto-ready berakhir di READY; sisanya di
 * NEED_REVIEW. READY di sini berarti data dokumennya lengkap dan tervalidasi, bukan bahwa
 * transaksinya sudah dibuat: NORMALIZING dan MATCHING adalah pekerjaan Phase 5 dan Phase 7
 * yang mengolah dokumen READY menjadi transaksi.
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
             * CLASSIFYING dan EXTRACTING dapat kembali ke QUEUED karena plan.md §29.3
             * menyediakan reprocess untuk dokumen apa pun, bukan hanya yang gagal, dan
             * karena percobaan yang terputus di tengah tahap harus dapat dipulihkan.
             */
            self::Classifying => [self::Extracting, self::NeedReview, self::Queued, self::Failed, self::Archived],

            /*
             * EXTRACTING dapat langsung ke READY. Sampai Phase 4, tahap terakhir yang
             * benar-benar berjalan adalah ekstraksi, dan dokumen yang ekstraksinya
             * diterima dengan confidence tinggi memang sudah selesai sebagai dokumen.
             * NORMALIZING tetap ada sebagai state karena plan.md §25.1 mendefinisikannya,
             * tetapi tidak ada dokumen yang memasukinya sebelum Phase 5 dibangun.
             */
            self::Extracting => [self::Ready, self::Normalizing, self::NeedReview, self::Queued, self::Failed, self::Archived],

            self::Normalizing => [self::Matching, self::NeedReview, self::Failed, self::Archived],
            self::Matching => [self::Ready, self::NeedReview, self::Failed, self::Archived],

            /*
             * plan.md §37 Phase 3 acceptance: "failure dapat diretry". Retry mengembalikan
             * dokumen ke QUEUED tanpa menyentuh berkas aslinya.
             *
             * Keduanya dapat kembali ke EXTRACTING karena koreksi reviewer atas jenis
             * dokumen membuka kembali tahap ekstraksi: schema yang dipakai berubah,
             * sehingga hasil sebelumnya tidak lagi berlaku. Melewatkan parse dan
             * klasifikasi memang disengaja — berkasnya sudah terbaca dan jenisnya sudah
             * ditetapkan manusia, jadi mengulang keduanya hanya membakar token untuk
             * prediksi yang akan langsung dikalahkan (plan.md §17.1).
             */
            self::Ready => [self::NeedReview, self::Extracting, self::Queued, self::Archived],
            self::NeedReview => [self::Queued, self::Extracting, self::Ready, self::Archived],
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
        if ($this === self::Queued) {
            return true;
        }

        /*
         * Status dianggap berjalan hanya bila tahap pipeline yang menghasilkannya memang
         * sudah dibangun. Status yang menyebut tahap phase berikutnya bukan status
         * berjalan: tidak ada worker yang akan mengubahnya, dan polling tanpa akhir hanya
         * membebani server tanpa pernah menghasilkan perubahan.
         *
         * Menurunkannya dari DocumentProcessingStage, bukan menuliskannya ulang, menjaga
         * kedua enum tidak dapat menyimpang ketika phase berikutnya dibangun.
         */
        foreach (DocumentProcessingStage::cases() as $stage) {
            if ($stage->runningStatus() === $this) {
                return $stage->isImplemented();
            }
        }

        return false;
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

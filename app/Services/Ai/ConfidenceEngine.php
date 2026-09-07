<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\Enums\ConfidenceBand;
use App\Domain\Business\Models\Business;

/**
 * Confidence engine (plan.md §15).
 *
 * Menggabungkan komponen skor plan.md §15.1 menjadi satu keputusan pita §15.2. Pada Phase 4
 * komponen yang tersedia adalah `document_type_confidence` dan `extraction_confidence`;
 * komponen entity, duplicate, related document, economic event, dan account mapping
 * menyusul pada phase yang membangunnya, dan engine ini menerima komponen apa pun tanpa
 * perubahan.
 *
 * Agregasinya adalah **minimum**, bukan rata-rata. Alasannya akuntansi, bukan statistik:
 * rata-rata membiarkan satu komponen yang sangat yakin menutupi komponen yang tidak yakin,
 * sehingga dokumen dengan jenis yang pasti tetapi angka yang kabur dapat lolos otomatis.
 * plan.md §32.2 menetapkan false auto approval harus sangat rendah, dan komponen terlemah
 * adalah yang menentukan risikonya.
 *
 * Seluruh aritmetika memakai bcmath pada string. Ambang §15.2 dibandingkan pada desimal
 * keempat, dan pembulatan float dapat memindahkan nilai melintasi ambang.
 */
class ConfidenceEngine
{
    private const SCALE = 4;

    public function __construct(
        private readonly string $defaultAutoReadyThreshold,
        private readonly string $defaultReviewThreshold,
    ) {}

    /**
     * @param  array<string, string|float|null>  $components
     */
    public function evaluate(array $components, ?Business $business = null): ConfidenceAssessment
    {
        $normalized = [];

        foreach ($components as $name => $value) {
            $normalized[$name] = $value === null ? null : $this->normalize($value);
        }

        $autoReady = $this->autoReadyThreshold($business);
        $review = $this->reviewThreshold($business);
        $score = $this->aggregate($normalized);

        return new ConfidenceAssessment(
            score: $score,
            band: $this->band($score, $autoReady, $review),
            components: $normalized,
            autoReadyThreshold: $autoReady,
            reviewThreshold: $review,
        );
    }

    /**
     * plan.md §15.2 dengan ambang yang dapat diatur per bisnis.
     */
    public function band(string $score, string $autoReady, string $review): ConfidenceBand
    {
        if (bccomp($score, $autoReady, self::SCALE) >= 0) {
            return ConfidenceBand::Ready;
        }

        if (bccomp($score, $review, self::SCALE) >= 0) {
            return ConfidenceBand::ReviewRecommended;
        }

        return ConfidenceBand::HumanConfirmationRequired;
    }

    public function autoReadyThreshold(?Business $business = null): string
    {
        return $this->threshold($business, 'ai_auto_ready_threshold', $this->defaultAutoReadyThreshold);
    }

    public function reviewThreshold(?Business $business = null): string
    {
        return $this->threshold($business, 'ai_review_threshold', $this->defaultReviewThreshold);
    }

    public function normalize(string|float $value): string
    {
        $decimal = bcadd(is_float($value) ? sprintf('%.4F', $value) : $value, '0', self::SCALE);

        if (bccomp($decimal, '0', self::SCALE) < 0) {
            return bcadd('0', '0', self::SCALE);
        }

        if (bccomp($decimal, '1', self::SCALE) > 0) {
            return bcadd('1', '0', self::SCALE);
        }

        return $decimal;
    }

    /**
     * @param  array<string, string|null>  $components
     */
    private function aggregate(array $components): string
    {
        $lowest = null;

        foreach ($components as $value) {
            if ($value === null) {
                continue;
            }

            if ($lowest === null || bccomp($value, $lowest, self::SCALE) < 0) {
                $lowest = $value;
            }
        }

        /*
         * Tanpa satu pun komponen, skornya nol, bukan satu. Dokumen yang belum dinilai
         * apa pun tidak boleh lolos otomatis hanya karena tidak ada bukti yang melawannya.
         */
        return $lowest ?? bcadd('0', '0', self::SCALE);
    }

    private function threshold(?Business $business, string $column, string $default): string
    {
        $profile = $business?->profile;

        /*
         * Nilai null pada profil berarti "ikuti default aplikasi", bukan nol. Membedakan
         * keduanya penting karena ambang nol akan meloloskan setiap dokumen secara
         * otomatis, dan itu tidak boleh menjadi akibat tak sengaja dari bisnis yang belum
         * pernah mengatur ambangnya.
         */
        $configured = $profile?->getAttribute($column);

        return $this->normalize(is_string($configured) ? $configured : $default);
    }
}

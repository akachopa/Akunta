<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\Enums\ConfidenceBand;

/**
 * Hasil penilaian confidence engine (plan.md §15).
 *
 * Menyimpan komponen penyusunnya, bukan hanya skor akhirnya, supaya reviewer dapat melihat
 * bagian mana yang lemah. "Confidence 0,72" tidak dapat ditindaklanjuti; "jenis dokumen
 * 0,93 tetapi ekstraksi 0,72" langsung mengarahkan reviewer memeriksa angkanya.
 */
final class ConfidenceAssessment
{
    /**
     * @param  array<string, string|null>  $components
     */
    public function __construct(
        public readonly string $score,
        public readonly ConfidenceBand $band,
        public readonly array $components,
        public readonly string $autoReadyThreshold,
        public readonly string $reviewThreshold,
    ) {}

    public function allowsAutomaticProcessing(): bool
    {
        return $this->band->allowsAutomaticProcessing();
    }

    /**
     * Alasan dokumen menunggu manusia, dalam bahasa yang dimengerti pemilik usaha.
     *
     * plan.md §16.3 melarang pertanyaan teknis, jadi pesan ini menyebut bagian yang lemah,
     * bukan nama komponen skornya.
     */
    public function reviewReason(): ?string
    {
        if ($this->allowsAutomaticProcessing()) {
            return null;
        }

        $weakest = $this->weakestComponent();

        $explanation = match ($weakest) {
            'document_type_confidence' => 'jenis dokumennya belum dapat dipastikan',
            'extraction_confidence' => 'sebagian data pada dokumen belum terbaca dengan yakin',
            default => 'tingkat keyakinan pembacaan dokumen belum mencukupi',
        };

        return sprintf(
            'Dokumen perlu diperiksa karena %s (keyakinan %s, ambang %s).',
            $explanation,
            $this->score,
            $this->autoReadyThreshold
        );
    }

    private function weakestComponent(): ?string
    {
        $weakest = null;
        $lowest = null;

        foreach ($this->components as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($lowest === null || bccomp($value, $lowest, 4) < 0) {
                $lowest = $value;
                $weakest = $name;
            }
        }

        return $weakest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'band' => $this->band->value,
            'band_label' => $this->band->label(),
            'components' => $this->components,
            'auto_ready_threshold' => $this->autoReadyThreshold,
            'review_threshold' => $this->reviewThreshold,
        ];
    }
}

<?php

declare(strict_types=1);

use App\Domain\Ai\Enums\ConfidenceBand;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Services\Ai\ConfidenceEngine;

/**
 * plan.md §15: confidence engine.
 *
 * Diuji tanpa database karena aturannya murni aritmetika: agregasi komponen dan
 * perbandingannya terhadap ambang. Ambang per bisnis diuji lewat feature test yang memang
 * memerlukan business profile.
 */
function engine(string $autoReady = '0.95', string $review = '0.80'): ConfidenceEngine
{
    return new ConfidenceEngine($autoReady, $review);
}

it('mengambil komponen terlemah sebagai skor dokumen', function (): void {
    $assessment = engine()->evaluate([
        'document_type_confidence' => '0.9900',
        'extraction_confidence' => '0.7200',
    ]);

    /*
     * Rata-rata keduanya adalah 0,855 dan akan lolos ambang review. plan.md §32.2 menuntut
     * false auto approval sangat rendah, dan komponen terlemahlah yang menentukan risikonya.
     */
    expect($assessment->score)->toBe('0.7200');
    expect($assessment->band)->toBe(ConfidenceBand::HumanConfirmationRequired);
});

it('memetakan skor ke pita sesuai ambang', function (): void {
    expect(engine()->evaluate(['a' => '0.9600'])->band)->toBe(ConfidenceBand::Ready);
    expect(engine()->evaluate(['a' => '0.8400'])->band)->toBe(ConfidenceBand::ReviewRecommended);
    expect(engine()->evaluate(['a' => '0.5000'])->band)->toBe(ConfidenceBand::HumanConfirmationRequired);
});

it('memasukkan nilai tepat di ambang ke pita yang lebih tinggi', function (): void {
    // Ambang plan.md §15.2 bersifat inklusif, dan batasnya dibandingkan pada desimal keempat.
    expect(engine()->evaluate(['a' => '0.9500'])->band)->toBe(ConfidenceBand::Ready);
    expect(engine()->evaluate(['a' => '0.9499'])->band)->toBe(ConfidenceBand::ReviewRecommended);
    expect(engine()->evaluate(['a' => '0.8000'])->band)->toBe(ConfidenceBand::ReviewRecommended);
    expect(engine()->evaluate(['a' => '0.7999'])->band)->toBe(ConfidenceBand::HumanConfirmationRequired);
});

it('menganggap dokumen tanpa komponen apa pun belum dinilai', function (): void {
    $assessment = engine()->evaluate(['document_type_confidence' => null]);

    // Skor nol, bukan satu: tidak ada bukti yang melawan bukan berarti dokumennya pasti.
    expect($assessment->score)->toBe('0.0000');
    expect($assessment->band)->toBe(ConfidenceBand::HumanConfirmationRequired);
});

it('mengabaikan komponen yang belum tersedia tanpa menurunkan skor', function (): void {
    /*
     * Komponen entity, duplicate, dan account mapping baru ada pada phase berikutnya.
     * Ketidakhadirannya tidak boleh dihitung sebagai ketidakyakinan.
     */
    $assessment = engine()->evaluate([
        'document_type_confidence' => '0.9700',
        'extraction_confidence' => '0.9600',
        'entity_confidence' => null,
        'account_mapping_confidence' => null,
    ]);

    expect($assessment->score)->toBe('0.9600');
    expect($assessment->band)->toBe(ConfidenceBand::Ready);
});

it('menyebutkan komponen terlemah pada alasan review', function (): void {
    $assessment = engine()->evaluate([
        'document_type_confidence' => '0.9900',
        'extraction_confidence' => '0.8100',
    ]);

    // plan.md §16.3: alasannya harus menunjuk bagian yang lemah, bukan menyebut nama skornya.
    expect($assessment->reviewReason())->toContain('sebagian data pada dokumen belum terbaca dengan yakin');
    expect($assessment->reviewReason())->not->toContain('extraction_confidence');

    $unclearType = engine()->evaluate([
        'document_type_confidence' => '0.6100',
        'extraction_confidence' => '0.9900',
    ]);

    expect($unclearType->reviewReason())->toContain('jenis dokumennya belum dapat dipastikan');
});

it('tidak memberi alasan review pada dokumen yang lolos otomatis', function (): void {
    $assessment = engine()->evaluate(['extraction_confidence' => '0.9900']);

    expect($assessment->reviewReason())->toBeNull();
    expect($assessment->allowsAutomaticProcessing())->toBeTrue();
});

it('menormalkan nilai di luar rentang alih-alih menolaknya', function (): void {
    /*
     * Confidence berasal dari provider di luar batas kepercayaan sistem. Nilai 1,4 tidak
     * boleh menjadi skor 1,4 yang lolos setiap ambang.
     */
    expect(engine()->normalize('1.4000'))->toBe('1.0000');
    expect(engine()->normalize('-0.2000'))->toBe('0.0000');
    expect(engine()->normalize(0.9))->toBe('0.9000');
});

it('mengarahkan hanya pita ready ke tahap berikutnya', function (): void {
    /*
     * plan.md §16.1 exception-based accounting: "disarankan diperiksa" tetapi lolos tanpa
     * diperiksa adalah kombinasi yang tidak berguna.
     *
     * Sejak Phase 5, pita ready mengarah ke NORMALIZING, bukan langsung READY: datanya
     * cukup dapat dipercaya untuk menjadi transaksi, dan dokumen baru selesai setelah
     * transaksinya terbentuk.
     */
    expect(ConfidenceBand::Ready->nextDocumentStatus())->toBe(DocumentStatus::Normalizing);
    expect(ConfidenceBand::ReviewRecommended->nextDocumentStatus())->toBe(DocumentStatus::NeedReview);
    expect(ConfidenceBand::HumanConfirmationRequired->nextDocumentStatus())->toBe(DocumentStatus::NeedReview);

    expect(ConfidenceBand::ReviewRecommended->allowsAutomaticProcessing())->toBeFalse();
});

it('memakai ambang yang diberikan alih-alih ambang bawaan', function (): void {
    // plan.md §15.2: "configurable per business".
    $strict = engine('0.99', '0.90');

    expect($strict->evaluate(['a' => '0.9600'])->band)->toBe(ConfidenceBand::ReviewRecommended);
    expect($strict->evaluate(['a' => '0.9900'])->band)->toBe(ConfidenceBand::Ready);
    expect($strict->evaluate(['a' => '0.8900'])->band)->toBe(ConfidenceBand::HumanConfirmationRequired);
});

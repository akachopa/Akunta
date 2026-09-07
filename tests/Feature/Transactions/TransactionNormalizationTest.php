<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentProcessingStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\ProcessingJobStatus;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionEvidenceType;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionEvidence;
use App\Services\TransactionNormalization\TransactionNormalizationService;
use Illuminate\Support\Facades\Queue;

/**
 * plan.md §37 Phase 5 acceptance:
 *
 * - satu rekening koran dapat menghasilkan banyak transaksi;
 * - satu invoice menghasilkan transaksi/evidence sesuai kebutuhan;
 * - transaksi dapat ditelusuri ke sumbernya.
 */
beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menghasilkan satu transaksi untuk setiap baris mutasi rekening koran', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    expect($document->processing_status)->toBe(DocumentStatus::Ready);

    $transactions = Transaction::query()
        ->withoutGlobalScopes()
        ->orderBy('reference')
        ->get();

    // Acceptance pertama Phase 5: satu rekening koran, banyak transaksi.
    expect($transactions)->toHaveCount(2);

    $setoran = $transactions->first();

    expect($setoran->reference)->toBe('TRX-000001');
    expect($setoran->transaction_date->toDateString())->toBe('2026-01-15');
    expect($setoran->amount)->toBe('2000000.00');
    expect($setoran->currency)->toBe('IDR');
    expect($setoran->status)->toBe(TransactionStatus::Ready);
    expect($setoran->source_type)->toBe(TransactionSourceType::BankStatement);

    /*
     * Kolom kredit berarti uang masuk ke rekening, kolom debit berarti keluar. Nominalnya
     * tetap positif dan arahnya yang membawa tandanya (plan.md §8.2).
     */
    expect($setoran->direction)->toBe(TransactionDirection::Inflow);
    expect($transactions->last()->direction)->toBe(TransactionDirection::Outflow);
    expect($transactions->last()->amount)->toBe('500000.00');

    // Confidence transaksi diwarisi dari dokumen sumbernya (plan.md §15.1).
    expect($setoran->overall_confidence)->toBe('0.9600');

    expect($setoran->description)->toContain('SETORAN TUNAI');
    expect($setoran->description)->toContain('Bank Mandiri');
});

it('menelusuri setiap transaksi kembali ke baris dokumen asalnya', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    $transactions = Transaction::query()
        ->withoutGlobalScopes()
        ->with(['source', 'evidence'])
        ->orderBy('reference')
        ->get();

    foreach ($transactions as $index => $transaction) {
        $source = $transaction->source;

        // Acceptance ketiga Phase 5: transaksi dapat ditelusuri ke sumbernya.
        expect($source->document_id)->toBe($document->getKey());
        expect($source->extraction_id)->toBe($document->acceptedExtraction->getKey());
        expect($source->source_reference)->toBe("row:{$index}");
        expect($source->row_index)->toBe($index);
        expect($source->page_number)->toBe(1);

        // plan.md §8.4: baris sumbernya melekat sebagai bukti, bukan hanya dokumennya.
        $bankRow = $transaction->evidence->firstWhere('type', TransactionEvidenceType::BankRow);

        expect($bankRow->row_reference)->toBe((string) $index);
        expect($bankRow->document_id)->toBe($document->getKey());
    }

    // Penelusuran juga berjalan mundur: dari dokumen ke transaksi yang lahir darinya.
    expect($document->transactions()->count())->toBe(2);
});

it('menghasilkan satu transaksi dan bukti pendukung dari sebuah faktur', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('purchase_invoice', 0.97);
    $this->fakeExtractorResponse($this->invoiceFields(confidence: 0.96), confidence: 0.96);

    $document = $this->runIntelligencePipeline($document);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->with('evidence')->sole();

    // Acceptance kedua Phase 5: satu faktur, satu transaksi beserta buktinya.
    expect($transaction->amount)->toBe('1110000.00');
    expect($transaction->transaction_date->toDateString())->toBe('2026-02-10');
    expect($transaction->source_type)->toBe(TransactionSourceType::Invoice);
    expect($transaction->source->source_reference)->toBe('document');
    expect($transaction->source->row_index)->toBeNull();

    // Faktur pembelian berarti nilai mengalir keluar dari bisnis.
    expect($transaction->direction)->toBe(TransactionDirection::Outflow);
    expect($transaction->counterparty_name)->toBe('PT Sumber Kertas');

    /*
     * Pajak tidak menjadi transaksi tersendiri dan tidak dilebur ke nominal: ia melekat
     * sebagai bukti agar rule engine Phase 9 dapat memisahkan PPN dari nilai barang
     * (plan.md §19.2, §44.3).
     */
    $tax = $transaction->evidence->firstWhere('field_key.value', 'tax');

    expect($tax->type)->toBe(TransactionEvidenceType::DocumentField);
    expect($tax->note)->toContain('110000.00');

    expect($transaction->evidence->firstWhere('field_key.value', 'subtotal'))->not->toBeNull();
});

it('menjadikan neto settlement sebagai nominal dan bruto beserta mdr sebagai bukti', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('qris_settlement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('settlement_date', '2026-01-31', 0.96),
            $this->extractedField('merchant_name', 'Warung Kopi Ibu', 0.96),
            $this->extractedField('merchant_id', 'QR-889912', 0.96),
            $this->extractedField('gross_amount', '5000000.00', 0.96),
            $this->extractedField('mdr_fee', '35000.00', 0.96),
            $this->extractedField('net_amount', '4965000.00', 0.96),
            $this->extractedField('transaction_count', '128', 0.96),
        ],
        confidence: 0.96,
        extractor: 'qris',
    );

    $document = $this->runIntelligencePipeline($document);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->with('evidence')->sole();

    /*
     * plan.md §44.3 melarang menganggap kas masuk sebagai pendapatan. Yang menjadi transaksi
     * adalah uang yang benar-benar masuk, sedangkan bruto dan MDR menunggu rule engine
     * Phase 9 memecahnya menjadi pendapatan dan beban.
     */
    expect($transaction->amount)->toBe('4965000.00');
    expect($transaction->direction)->toBe(TransactionDirection::Inflow);
    expect($transaction->source_type)->toBe(TransactionSourceType::Settlement);

    $notes = $transaction->evidence->pluck('note')->implode(' | ');

    expect($notes)->toContain('5000000.00');
    expect($notes)->toContain('35000.00');
    expect($notes)->toContain('128 transaksi');
});

it('membaca daftar transaksi berkolom nilai bertanda dari laporan penjualan', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('marketplace_report', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('merchant_name', 'Toko Sinar di Marketplace', 0.96),
            $this->extractedField('period_end', '2026-01-31', 0.96),
        ],
        rows: [
            [
                'date' => '2026-01-05',
                'description' => 'Pesanan INV-9001',
                'amount' => '450000.00',
                'page_number' => 1,
                'source_text' => '05/01 Pesanan INV-9001 450.000',
            ],
            [
                'date' => '2026-01-07',
                'description' => 'Refund INV-8890',
                'amount' => '-120000.00',
                'page_number' => 1,
                'source_text' => '07/01 Refund INV-8890 -120.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'transaction_list',
    );

    $document = $this->runIntelligencePipeline($document);

    expect($document->processing_status)->toBe(DocumentStatus::Ready);

    $transactions = Transaction::query()->withoutGlobalScopes()->orderBy('reference')->get();

    expect($transactions)->toHaveCount(2);

    // Tanda pada kolom nilai menentukan arahnya; nominalnya disimpan positif.
    expect($transactions->first()->direction)->toBe(TransactionDirection::Inflow);
    expect($transactions->first()->amount)->toBe('450000.00');

    expect($transactions->last()->direction)->toBe(TransactionDirection::Outflow);
    expect($transactions->last()->amount)->toBe('120000.00');

    expect($transactions->first()->source_type)->toBe(TransactionSourceType::Spreadsheet);
    expect($transactions->first()->counterparty_name)->toBe('Toko Sinar di Marketplace');
});

it('menolak seluruh mutasi ketika satu baris memuat debit dan kredit sekaligus', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    $document = $this->normalizedBankStatement($business, $owner, rows: [
        [
            'date' => '2026-01-15',
            'description' => 'MUTASI GANDA',
            'debit' => '500000.00',
            'credit' => '2000000.00',
            'balance' => '6500000.00',
            'page_number' => 1,
            'source_text' => '15/01 MUTASI GANDA',
        ],
    ]);

    /*
     * Kolom yang tergeser saat parsing menghasilkan tepat bentuk ini. Menebak arahnya
     * berarti menaruh uang di sisi yang salah, jadi dokumennya dikembalikan ke manusia
     * tanpa menyisakan satu pun transaksi.
     */
    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->review_reason)->toContain('arah uangnya tidak dapat dipastikan');
    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Normalize)
        ->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Skipped);
});

it('menolak mutasi yang tidak menjelaskan perubahan saldo', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();

    /*
     * Saldo akhir dikoreksi reviewer setelah ekstraksi selesai, sehingga mutasi yang semula
     * konsisten tidak lagi konsisten. Karena itu identitas saldo diperiksa ulang saat
     * normalisasi, bukan hanya saat ekstraksi (plan.md §19.1).
     */
    $document = $this->normalizedBankStatement($business, $owner);

    expect($document->processing_status)->toBe(DocumentStatus::Ready);

    $closing = $document->fields()->documentLevel()->where('field_key', 'closing_balance')->sole();
    $closing->update(['value_number' => '9999999.00']);

    $document->transitionTo(DocumentStatus::Normalizing);

    $document = $this->runNormalization($document);

    expect($document->processing_status)->toBe(DocumentStatus::NeedReview);
    expect($document->review_reason)->toContain('perubahan saldo');
    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('menggantikan transaksi lama ketika dokumen dinormalisasi ulang', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    $first = Transaction::query()->withoutGlobalScopes()->pluck('id')->all();

    expect($first)->toHaveCount(2);

    $document->transitionTo(DocumentStatus::Normalizing);
    $document = $this->runNormalization($document);

    $second = Transaction::query()->withoutGlobalScopes()->get();

    /*
     * plan.md §40: job idempotent. Normalisasi ulang menggantikan hasil lama alih-alih
     * menambahkan versi kedua dari baris sumber yang sama.
     */
    expect($second)->toHaveCount(2);
    expect($second->pluck('id')->all())->not->toBe($first);
    expect($second->pluck('reference')->all())->toBe(['TRX-000003', 'TRX-000004']);

    // Bukti transaksi lama ikut hilang bersama transaksinya, tidak menggantung.
    expect(TransactionEvidence::query()->withoutGlobalScopes()->count())->toBe(4);
});

it('tidak menormalisasi ulang dokumen yang transaksinya sudah diputuskan manusia', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->orderBy('reference')->first();

    $transaction->transitionTo(TransactionStatus::Rejected);

    $document->transitionTo(DocumentStatus::Normalizing);
    $document = $this->runNormalization($document);

    /*
     * plan.md §31 dan §44.7: keputusan manusia tidak boleh dibatalkan diam-diam oleh sebuah
     * job. Normalisasi berhenti dan alasannya tercatat pada riwayat tahap.
     */
    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(2);
    expect($transaction->refresh()->status)->toBe(TransactionStatus::Rejected);
    expect($document->processing_status)->toBe(DocumentStatus::Ready);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Normalize)
        ->orderByDesc('attempt')
        ->first();

    expect($job->status)->toBe(ProcessingJobStatus::Skipped);
    expect($job->error_message)->toContain('tidak dinormalisasi ulang');
});

it('menyelesaikan dokumen tanpa transaksi ketika jenisnya belum punya normalizer', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('tax_document', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('document_date', '2026-01-31', 0.96),
            $this->extractedField('total', '750000.00', 0.96),
        ],
        confidence: 0.96,
        extractor: 'invoice',
    );

    $document = $this->runIntelligencePipeline($document);

    /*
     * Memaksakan bentuk faktur pada dokumen pajak akan menghasilkan transaksi yang nominalnya
     * benar tetapi artinya salah. Dokumen tetap READY karena datanya memang selesai dibaca.
     */
    expect($document->processing_status)->toBe(DocumentStatus::Ready);
    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);

    $job = $document->processingJobs()
        ->withoutGlobalScopes()
        ->forStage(DocumentProcessingStage::Normalize)
        ->sole();

    expect($job->status)->toBe(ProcessingJobStatus::Skipped);
    expect($job->error_message)->toContain('belum tersedia');
});

it('mencatat pembuatan transaksi pada audit trail', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    // plan.md §31, §45.6: transaksi adalah data yang akan menjadi jurnal.
    $log = AuditLog::query()->where('action', 'document.normalized')->sole();

    expect($log->after['transaction_count'])->toBe(2);
    expect($log->before['transaction_count'])->toBe(0);
});

it('melewati dokumen yang tidak sedang menunggu normalisasi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->normalizedBankStatement($business, $owner);

    $again = app(TenantContext::class)->withBusiness(
        $business,
        fn (): bool => app(TransactionNormalizationService::class)->normalize($document)
    );

    expect($again)->toBeFalse();
    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('menomori transaksi berurutan per bisnis', function (): void {
    [$ownerA, $businessA] = $this->provisionBusinessWithOwner();
    [$ownerB, $businessB] = $this->provisionBusinessWithOwner('Toko Kedua');

    $this->normalizedBankStatement($businessA, $ownerA);
    $this->normalizedBankStatement($businessB, $ownerB);

    // Nomor berjalan per bisnis, bukan global: dua bisnis tidak melihat nomor berlubang.
    $references = fn (Business $business): array => Transaction::query()
        ->withoutGlobalScopes()
        ->where('business_id', $business->getKey())
        ->orderBy('reference')
        ->pluck('reference')
        ->all();

    expect($references($businessA))->toBe(['TRX-000001', 'TRX-000002']);
    expect($references($businessB))->toBe(['TRX-000001', 'TRX-000002']);
});

it('menolak perubahan dan penghapusan bukti transaksi', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var TransactionEvidence $evidence */
    $evidence = TransactionEvidence::query()->withoutGlobalScopes()->first();

    // plan.md §31: bukti hanya boleh hilang bersama transaksi yang memilikinya.
    expect(fn () => $evidence->update(['note' => 'diubah']))->toThrow(RuntimeException::class);
    expect(fn () => $evidence->delete())->toThrow(RuntimeException::class);
});

it('menolak transisi status transaksi yang melanggar state machine', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->first();

    // Pipeline Phase 6–9 sudah menempatkan transaksi di Ready.
    $transaction->transitionTo(TransactionStatus::Approved);
    $transaction->transitionTo(TransactionStatus::Posted);

    // plan.md §44.6: transaksi yang sudah masuk ledger hanya dikoreksi lewat reversal journal.
    expect(fn () => $transaction->transitionTo(TransactionStatus::Ready))
        ->toThrow(App\Domain\Transactions\Exceptions\InvalidTransactionTransition::class);
});

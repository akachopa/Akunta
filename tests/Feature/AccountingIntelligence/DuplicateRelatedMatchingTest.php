<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Transactions\Enums\TransactionRelationType;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Models\TransactionRelation;
use App\Services\Accounting\JournalProposalService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('tidak menjurnal dua kali untuk dokumen dengan hash yang sama', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $first = $this->normalizedBankStatement($business, $owner);

    $firstJournals = JournalEntry::query()
        ->withoutGlobalScopes()
        ->where('source_type', JournalProposalService::SOURCE_TRANSACTION)
        ->count();

    expect($firstJournals)->toBe(2);
    expect($first->processing_status)->toBe(DocumentStatus::Ready);

    $duplicate = $this->ingestDocument($business, $owner, $this->csvFile());
    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('bank_name', 'Bank Mandiri', 0.96),
            $this->extractedField('account_number', '1230004567', 0.96),
            $this->extractedField('period_end', '2026-01-31', 0.96),
            $this->extractedField('opening_balance', '5000000.00', 0.96),
            $this->extractedField('closing_balance', '6500000.00', 0.96),
        ],
        rows: [
            [
                'date' => '2026-01-15',
                'description' => 'SETORAN TUNAI',
                'debit' => null,
                'credit' => '2000000.00',
                'balance' => '7000000.00',
                'page_number' => 1,
                'source_text' => '15/01 SETORAN TUNAI 2.000.000',
            ],
            [
                'date' => '2026-01-20',
                'description' => 'BIAYA ADMIN',
                'debit' => '500000.00',
                'credit' => null,
                'balance' => '6500000.00',
                'page_number' => 1,
                'source_text' => '20/01 BIAYA ADMIN 500.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'bank_statement',
    );
    $this->runIntelligencePipeline($duplicate);

    $dupes = Transaction::query()->withoutGlobalScopes()->fromDocument($duplicate)->get();

    expect($dupes)->toHaveCount(2);
    expect($dupes->every(fn (Transaction $transaction): bool => $transaction->status === TransactionStatus::NeedReview))->toBeTrue();

    expect(
        TransactionRelation::query()
            ->withoutGlobalScopes()
            ->where('type', TransactionRelationType::Duplicate->value)
            ->count()
    )->toBeGreaterThan(0);

    expect(
        JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('source_type', JournalProposalService::SOURCE_TRANSACTION)
            ->count()
    )->toBe($firstJournals);
});

it('menautkan faktur pembelian dengan pembayaran bank beserta confidence', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $invoice = $this->normalizedPurchaseInvoice($business, $owner);

    $payment = $this->ingestDocument($business, $owner);
    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('bank_name', 'Bank Mandiri', 0.96),
            $this->extractedField('account_number', '1230004567', 0.96),
            $this->extractedField('period_end', '2026-02-28', 0.96),
            $this->extractedField('opening_balance', '5000000.00', 0.96),
            $this->extractedField('closing_balance', '3890000.00', 0.96),
        ],
        rows: [
            [
                'date' => '2026-02-12',
                'description' => 'TRANSFER KE PT SUMBER KERTAS INV/2026/0012',
                'debit' => '1110000.00',
                'credit' => null,
                'balance' => '3890000.00',
                'page_number' => 1,
                'source_text' => '12/02 TRANSFER KE PT SUMBER KERTAS INV/2026/0012 1.110.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'bank_statement',
    );
    $this->runIntelligencePipeline($payment);

    $relation = TransactionRelation::query()
        ->withoutGlobalScopes()
        ->where('type', TransactionRelationType::Related->value)
        ->first();

    expect($relation)->not->toBeNull();
    expect($relation->confidence)->not->toBeNull();
    expect($relation->reasons)->toContain('Faktur dan pembayaran.');

    $invoiceTx = Transaction::query()->withoutGlobalScopes()->fromDocument($invoice)->first();
    $paymentTx = Transaction::query()->withoutGlobalScopes()->fromDocument($payment)->first();

    expect($invoiceTx->journalEntries()->count())->toBe(1);
    expect($paymentTx->journalEntries()->count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Models\AiFeedback;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('menolak kode peristiwa di luar taksonomi dari worker', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('bank_name', 'Bank Mandiri', 0.96),
            $this->extractedField('account_number', '1230004567', 0.96),
            $this->extractedField('period_end', '2026-01-31', 0.96),
            $this->extractedField('opening_balance', '5000000.00', 0.96),
            $this->extractedField('closing_balance', '5750000.00', 0.96),
        ],
        rows: [
            [
                'date' => '2026-01-18',
                'description' => 'TRANSFER DARI PELANGGAN ABC',
                'debit' => null,
                'credit' => '750000.00',
                'balance' => '5750000.00',
                'page_number' => 1,
                'source_text' => '18/01 TRANSFER DARI PELANGGAN ABC 750.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'bank_statement',
    );

    $this->fakeWorkerRoute('/v1/classify-event', fn (): mixed => Http::response([
        'event_code' => 'REVENUE_MAGIC',
        'confidence' => 0.99,
        'reason' => 'Kode karangan.',
        'missing_information' => [],
        'provider' => 'rogue',
        'model' => 'rogue',
        'prompt_version' => '1.0',
        'latency_ms' => 1,
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => '0', 'currency' => 'USD'],
        'raw' => [],
    ]));

    $this->runIntelligencePipeline($document);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->first();

    expect($transaction->economicEvent?->code)->not->toBe('REVENUE_MAGIC');
    expect(EconomicEventCode::tryFrom((string) $transaction->economicEvent?->code))->not->toBeNull();
    expect($transaction->status)->toBe(TransactionStatus::NeedReview);
});

it('menyimpan koreksi reviewer ke umpan balik AI', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%BIAYA ADMIN%')
        ->first();

    expect($transaction->economicEvent?->code)->toBe(EconomicEventCode::BankFee->value);

    $this->actingAs($owner)
        ->post("/businesses/{$business->getKey()}/transactions/{$transaction->getKey()}/classify", [
            'event_code' => EconomicEventCode::UtilityExpense->value,
            'reason' => 'Ini tagihan listrik yang terdebet otomatis.',
        ])
        ->assertRedirect();

    $feedback = AiFeedback::query()
        ->withoutGlobalScopes()
        ->where('task', AiTask::ClassifyEconomicEvent->value)
        ->sole();

    expect($feedback->original_value)->toBe(EconomicEventCode::BankFee->value);
    expect($feedback->final_value)->toBe(EconomicEventCode::UtilityExpense->value);
    expect($feedback->reviewer_id)->toBe($owner->getKey());
    expect($transaction->refresh()->economicEvent?->code)->toBe(EconomicEventCode::UtilityExpense->value);
});

it('mengirim transaksi berconfidence rendah ke antrean review', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $document = $this->ingestDocument($business, $owner);

    $this->fakeParserSuccess();
    $this->fakeClassifierSuccess('bank_statement', 0.97);
    $this->fakeExtractorResponse(
        [
            $this->extractedField('bank_name', 'Bank Mandiri', 0.96),
            $this->extractedField('account_number', '1230004567', 0.96),
            $this->extractedField('period_end', '2026-01-31', 0.96),
            $this->extractedField('opening_balance', '5000000.00', 0.96),
            $this->extractedField('closing_balance', '5750000.00', 0.96),
        ],
        rows: [
            [
                'date' => '2026-01-18',
                'description' => 'TRANSFER DARI PELANGGAN ABC',
                'debit' => null,
                'credit' => '750000.00',
                'balance' => '5750000.00',
                'page_number' => 1,
                'source_text' => '18/01 TRANSFER DARI PELANGGAN ABC 750.000',
            ],
        ],
        confidence: 0.96,
        extractor: 'bank_statement',
    );

    $this->runIntelligencePipeline($document);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()->withoutGlobalScopes()->first();

    expect($transaction->status)->toBe(TransactionStatus::NeedReview);
    expect($transaction->review_reason)->not->toBeNull();
});

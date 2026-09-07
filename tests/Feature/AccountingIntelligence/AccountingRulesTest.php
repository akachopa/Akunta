<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\JournalLineSide;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Support\AccountingRuleDefinition;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transactions\Models\Transaction;
use App\Services\Accounting\JournalProposalService;
use App\Services\EconomicEvents\EconomicEventClassificationService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->fakeDocumentDisk();
    Queue::fake();
});

it('memuat rule seimbang untuk setiap peristiwa ekonomi', function (): void {
    foreach (EconomicEventCode::cases() as $event) {
        $lines = AccountingRuleDefinition::lines($event);
        $debits = array_filter($lines, fn (array $line): bool => $line['side'] === JournalLineSide::Debit);
        $credits = array_filter($lines, fn (array $line): bool => $line['side'] === JournalLineSide::Credit);

        expect($debits)->not->toBeEmpty();
        expect($credits)->not->toBeEmpty();
    }
});

it('tidak memasukkan transfer internal, modal, dan pokok pinjaman ke P dan L', function (): void {
    $service = app(JournalProposalService::class);

    foreach ([
        EconomicEventCode::BankTransferInternal,
        EconomicEventCode::CashToBankTransfer,
        EconomicEventCode::BankToCashTransfer,
        EconomicEventCode::OwnerCapital,
        EconomicEventCode::OwnerWithdrawal,
        EconomicEventCode::LoanReceived,
        EconomicEventCode::LoanPrincipalPayment,
    ] as $event) {
        $types = $service->accountTypesFor($event);

        expect($types)->not->toBeEmpty();

        foreach ($types as $type) {
            expect($type->isProfitAndLoss())->toBeFalse(
                sprintf('%s tidak boleh memakai akun %s.', $event->value, $type->value)
            );
        }
    }
});

it('mengusulkan jurnal draft yang seimbang tanpa memposting', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    $setoran = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%SETORAN TUNAI%')
        ->first();

    expect($setoran->economicEvent?->code)->toBe(EconomicEventCode::CashToBankTransfer->value);

    /** @var JournalEntry $journal */
    $journal = $setoran->journalEntries()->first();

    expect($journal)->not->toBeNull();
    expect($journal->status)->toBe(JournalEntryStatus::Draft);
    expect($journal->isBalanced())->toBeTrue();
    expect($journal->posted_at)->toBeNull();

    foreach ($journal->lines as $line) {
        expect($line->account?->account_type->isProfitAndLoss())->toBeFalse();
    }
});

it('mengusulkan jurnal aset/kewajiban untuk pokok pinjaman, bukan beban', function (): void {
    [$owner, $business] = $this->provisionBusinessWithOwner();
    $this->normalizedBankStatement($business, $owner);

    /** @var Transaction $transaction */
    $transaction = Transaction::query()
        ->withoutGlobalScopes()
        ->where('description', 'like', '%BIAYA ADMIN%')
        ->first();

    app(TenantContext::class)->withBusiness($business, function () use ($transaction, $owner): void {
        app(EconomicEventClassificationService::class)->correct(
            $transaction,
            EconomicEventCode::LoanPrincipalPayment,
            $owner,
            'Pokok cicilan pinjaman.',
        );
    });

    $proposal = app(TenantContext::class)->withBusiness(
        $business,
        fn (): array => app(JournalProposalService::class)->propose($transaction->refresh())
    );

    expect($proposal['journal_id'])->not->toBeNull();

    $journal = JournalEntry::query()->withoutGlobalScopes()->find($proposal['journal_id']);

    expect($journal->status)->toBe(JournalEntryStatus::Draft);
    expect($journal->isBalanced())->toBeTrue();

    foreach ($journal->lines as $line) {
        expect($line->account?->account_type->isProfitAndLoss())->toBeFalse();
    }
});

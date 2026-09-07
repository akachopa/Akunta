<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;

/*
 * plan.md §25.4 (state machine journal), §20.1 (state periode), dan §12.2 (tipe akun)
 * dikunci di sini supaya perubahan pada enum tidak lolos tanpa keputusan sadar.
 */

it('menyediakan delapan account type sesuai plan', function (): void {
    expect(AccountType::values())->toEqualCanonicalizing([
        'asset',
        'liability',
        'equity',
        'revenue',
        'cost_of_sales',
        'operating_expense',
        'other_income',
        'other_expense',
    ]);
});

it('menetapkan normal balance yang benar per account type', function (): void {
    expect(AccountType::Asset->normalBalance())->toBe(NormalBalance::Debit);
    expect(AccountType::CostOfSales->normalBalance())->toBe(NormalBalance::Debit);
    expect(AccountType::OperatingExpense->normalBalance())->toBe(NormalBalance::Debit);
    expect(AccountType::OtherExpense->normalBalance())->toBe(NormalBalance::Debit);
    expect(AccountType::Liability->normalBalance())->toBe(NormalBalance::Credit);
    expect(AccountType::Equity->normalBalance())->toBe(NormalBalance::Credit);
    expect(AccountType::Revenue->normalBalance())->toBe(NormalBalance::Credit);
    expect(AccountType::OtherIncome->normalBalance())->toBe(NormalBalance::Credit);
});

it('mengikat setiap account role ke satu account type', function (): void {
    foreach (AccountRole::cases() as $role) {
        expect($role->expectedAccountType())->toBeInstanceOf(AccountType::class);
    }

    expect(AccountRole::CashOrBank->expectedAccountType())->toBe(AccountType::Asset);
    expect(AccountRole::AccountsReceivable->expectedAccountType())->toBe(AccountType::Asset);
    expect(AccountRole::AccountsPayable->expectedAccountType())->toBe(AccountType::Liability);
    expect(AccountRole::SalesRevenue->expectedAccountType())->toBe(AccountType::Revenue);
    expect(AccountRole::OwnerEquity->expectedAccountType())->toBe(AccountType::Equity);
});

it('mengizinkan transisi journal hanya sesuai plan.md §25.4', function (): void {
    expect(JournalEntryStatus::Draft->canTransitionTo(JournalEntryStatus::PendingApproval))->toBeTrue();
    expect(JournalEntryStatus::Draft->canTransitionTo(JournalEntryStatus::Approved))->toBeTrue();
    expect(JournalEntryStatus::Draft->canTransitionTo(JournalEntryStatus::Posted))->toBeFalse();
    expect(JournalEntryStatus::Draft->canTransitionTo(JournalEntryStatus::Reversed))->toBeFalse();

    expect(JournalEntryStatus::PendingApproval->canTransitionTo(JournalEntryStatus::Approved))->toBeTrue();
    expect(JournalEntryStatus::PendingApproval->canTransitionTo(JournalEntryStatus::Posted))->toBeFalse();

    expect(JournalEntryStatus::Approved->canTransitionTo(JournalEntryStatus::Posted))->toBeTrue();

    expect(JournalEntryStatus::Posted->canTransitionTo(JournalEntryStatus::Reversed))->toBeTrue();
    expect(JournalEntryStatus::Posted->canTransitionTo(JournalEntryStatus::Draft))->toBeFalse();
    expect(JournalEntryStatus::Posted->canTransitionTo(JournalEntryStatus::Approved))->toBeFalse();

    expect(JournalEntryStatus::Reversed->allowedTransitions())->toBe([]);
});

it('menandai posted dan reversed sebagai immutable', function (): void {
    expect(JournalEntryStatus::Posted->isImmutable())->toBeTrue();
    expect(JournalEntryStatus::Reversed->isImmutable())->toBeTrue();
    expect(JournalEntryStatus::Draft->isImmutable())->toBeFalse();
    expect(JournalEntryStatus::PendingApproval->isImmutable())->toBeFalse();
    expect(JournalEntryStatus::Approved->isImmutable())->toBeFalse();
});

it('memblokir aktivitas journal hanya pada periode tertutup', function (): void {
    expect(AccountingPeriodStatus::Open->acceptsJournalActivity())->toBeTrue();
    expect(AccountingPeriodStatus::Reviewing->acceptsJournalActivity())->toBeTrue();
    expect(AccountingPeriodStatus::ReadyToClose->acceptsJournalActivity())->toBeTrue();
    expect(AccountingPeriodStatus::Reopened->acceptsJournalActivity())->toBeTrue();
    expect(AccountingPeriodStatus::Closed->acceptsJournalActivity())->toBeFalse();
});

it('mengizinkan transisi periode hanya sesuai plan.md §20.1', function (): void {
    expect(AccountingPeriodStatus::Open->canTransitionTo(AccountingPeriodStatus::Closed))->toBeTrue();
    expect(AccountingPeriodStatus::Open->canTransitionTo(AccountingPeriodStatus::Reopened))->toBeFalse();
    expect(AccountingPeriodStatus::Closed->canTransitionTo(AccountingPeriodStatus::Reopened))->toBeTrue();
    expect(AccountingPeriodStatus::Closed->canTransitionTo(AccountingPeriodStatus::Open))->toBeFalse();
    expect(AccountingPeriodStatus::Reopened->canTransitionTo(AccountingPeriodStatus::Closed))->toBeTrue();
});

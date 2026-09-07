<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Business\Enums\RoleSlug;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * plan.md §24.3 menetapkan constraint baris journal di level skema. Test ini menembus
 * seluruh lapisan aplikasi dan menulis langsung ke database, sehingga yang diuji benar-
 * benar constraint PostgreSQL, bukan validasi PHP.
 */

beforeEach(function (): void {
    [, $this->business] = $this->provisionBusinessWithOwner();
    $this->accountant = $this->addMember($this->business, RoleSlug::Accountant);
    $this->entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000');
});

function insertRawLine(string $businessId, string $entryId, string $accountId, array $overrides): void
{
    DB::table('journal_entry_lines')->insert([
        'id' => Str::uuid()->toString(),
        'journal_entry_id' => $entryId,
        'business_id' => $businessId,
        'account_id' => $accountId,
        'line_number' => 99,
        'debit' => 0,
        'credit' => 0,
        'created_at' => now(),
        ...$overrides,
    ]);
}

it('menolak debit negatif di level database', function (): void {
    expect(fn () => insertRawLine(
        $this->business->getKey(),
        $this->entry->getKey(),
        $this->account($this->business, '1102')->getKey(),
        ['debit' => -1000, 'credit' => 0],
    ))->toThrow(QueryException::class);
});

it('menolak kredit negatif di level database', function (): void {
    expect(fn () => insertRawLine(
        $this->business->getKey(),
        $this->entry->getKey(),
        $this->account($this->business, '1102')->getKey(),
        ['debit' => 0, 'credit' => -1000],
    ))->toThrow(QueryException::class);
});

it('menolak baris yang mengisi debit dan kredit sekaligus di level database', function (): void {
    expect(fn () => insertRawLine(
        $this->business->getKey(),
        $this->entry->getKey(),
        $this->account($this->business, '1102')->getKey(),
        ['debit' => 1000, 'credit' => 1000],
    ))->toThrow(QueryException::class);
});

it('menolak baris tanpa nilai di level database', function (): void {
    expect(fn () => insertRawLine(
        $this->business->getKey(),
        $this->entry->getKey(),
        $this->account($this->business, '1102')->getKey(),
        ['debit' => 0, 'credit' => 0],
    ))->toThrow(QueryException::class);
});

it('menyimpan uang sebagai numeric dua desimal tanpa pembulatan float', function (): void {
    $entry = $this->postSimpleJournal(
        $this->business,
        $this->accountant,
        '1102',
        '4100',
        '99999999999.99'
    );

    $stored = DB::table('journal_entry_lines')
        ->where('journal_entry_id', $entry->getKey())
        ->orderBy('line_number')
        ->pluck('debit')
        ->all();

    expect($stored[0])->toBe('99999999999.99');
});

it('menolak dua akun aktif memperebutkan account role tunggal', function (): void {
    expect(fn () => app(ChartOfAccountsService::class)->createAccount($this->business, [
        'code' => '1250',
        'name' => 'Piutang Usaha Kedua',
        'account_type' => AccountType::Asset->value,
        'account_role' => AccountRole::AccountsReceivable->value,
    ]))->toThrow(QueryException::class);
});

it('mengizinkan beberapa akun kas atau bank memakai role yang sama', function (): void {
    $account = app(ChartOfAccountsService::class)->createAccount($this->business, [
        'code' => '1103',
        'name' => 'Bank Kedua',
        'account_type' => AccountType::Asset->value,
        'account_role' => AccountRole::CashOrBank->value,
    ]);

    expect($account->account_role)->toBe(AccountRole::CashOrBank);
});

it('menolak nomor entry duplikat dalam satu bisnis', function (): void {
    expect(fn () => DB::table('journal_entries')->insert([
        'id' => Str::uuid()->toString(),
        'business_id' => $this->business->getKey(),
        'accounting_period_id' => $this->entry->accounting_period_id,
        'entry_number' => $this->entry->entry_number,
        'entry_date' => '2026-01-15',
        'description' => 'Duplikat',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

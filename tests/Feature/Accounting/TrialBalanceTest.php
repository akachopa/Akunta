<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Business\Enums\RoleSlug;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\TrialBalanceService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/*
 * plan.md §37 Phase 2 acceptance: "trial balance benar".
 *
 * plan.md §44.5 melarang perhitungan laporan dari raw transaction table dan §45.11
 * mewajibkan laporan hanya membaca posted journal.
 */

beforeEach(function (): void {
    [$this->owner, $this->business] = $this->provisionBusinessWithOwner();
    $this->accountant = $this->addMember($this->business, RoleSlug::Accountant);
    $this->trialBalance = app(TrialBalanceService::class);
    $this->posting = app(JournalPostingService::class);
});

/**
 * @return array<string, array{debit: string, credit: string}>
 */
function rowsByCode(array $report): array
{
    $rows = [];

    foreach ($report['rows'] as $row) {
        $rows[$row['code']] = ['debit' => $row['debit_balance'], 'credit' => $row['credit_balance']];
    }

    return $rows;
}

it('mengembalikan neraca saldo kosong sebelum ada journal terposting', function (): void {
    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    expect($report['rows'])->toBe([]);
    expect($report['total_debit'])->toBe('0.00');
    expect($report['total_credit'])->toBe('0.00');
    expect($report['is_balanced'])->toBeTrue();
});

it('menyeimbangkan total debit dan kredit', function (): void {
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '5000000');
    $this->postSimpleJournal($this->business, $this->accountant, '6100', '1102', '2000000');

    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    expect($report['is_balanced'])->toBeTrue();
    expect($report['total_debit'])->toBe($report['total_credit']);
    expect($report['total_debit'])->toBe('5000000.00');

    $rows = rowsByCode($report);

    expect($rows['1102']['debit'])->toBe('3000000.00');
    expect($rows['4100']['credit'])->toBe('5000000.00');
    expect($rows['6100']['debit'])->toBe('2000000.00');
});

it('mengabaikan journal yang belum diposting', function (): void {
    $this->posting->createDraft($this->business, new JournalEntryData(
        entryDate: Carbon::parse('2026-01-15'),
        description: 'Draft tidak boleh masuk laporan',
        lines: [
            new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '9000000'),
            new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '9000000'),
        ],
    ), $this->owner);

    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    expect($report['rows'])->toBe([]);
});

it('menghapus pengaruh entry yang sudah dibalik', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '4000000');

    $before = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));
    expect($before['total_debit'])->toBe('4000000.00');

    $this->posting->reverse($entry, $this->accountant, 'Salah pencatatan');

    $after = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    /*
     * Entry asli tetap di ledger dengan status 'reversed' dan reversal entry berstatus
     * 'posted'; keduanya saling menetralkan sehingga saldo kembali nol.
     */
    expect($after['total_debit'])->toBe('0.00');
    expect($after['total_credit'])->toBe('0.00');

    // Akunnya tetap tampil dengan mutasi dua sisi supaya jejak koreksi terlihat.
    $bank = collect($after['rows'])->firstWhere('code', '1102');
    expect($bank['movement_debit'])->toBe('4000000.00');
    expect($bank['movement_credit'])->toBe('4000000.00');
    expect($after['is_balanced'])->toBeTrue();
});

it('membatasi laporan pada rentang tanggal periode', function (): void {
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '1000000', '2026-01-10');
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '2000000', '2026-02-10');

    $january = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));
    $february = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-02-15'));

    expect($january['total_debit'])->toBe('1000000.00');
    expect($february['total_debit'])->toBe('2000000.00');

    $cumulative = $this->trialBalance->asOf($this->business, Carbon::parse('2026-02-28'));
    expect($cumulative['total_debit'])->toBe('3000000.00');
});

it('menampilkan satu sisi per akun berdasarkan selisih mutasinya', function (): void {
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '3000000');
    $this->postSimpleJournal($this->business, $this->accountant, '6100', '1102', '1000000');

    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    $bank = collect($report['rows'])->firstWhere('code', '1102');

    expect($bank['movement_debit'])->toBe('3000000.00');
    expect($bank['movement_credit'])->toBe('1000000.00');
    expect($bank['debit_balance'])->toBe('2000000.00');
    expect($bank['credit_balance'])->toBe('0.00');
});

it('menampilkan saldo tidak wajar apa adanya', function (): void {
    // Kas dikreditkan lebih besar daripada didebit: saldo kas menjadi negatif.
    $this->postSimpleJournal($this->business, $this->accountant, '6100', '1101', '500000');

    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));
    $cash = collect($report['rows'])->firstWhere('code', '1101');

    expect($cash['credit_balance'])->toBe('500000.00');
    expect($cash['debit_balance'])->toBe('0.00');

    // signed_balance mengikuti arah normal balance akun, jadi bernilai negatif.
    expect(Money::isNegative($cash['signed_balance']))->toBeTrue();
});

it('tidak mencampur ledger antar bisnis', function (): void {
    [, $businessLain] = $this->provisionBusinessWithOwner('Bisnis Lain');
    $akuntanLain = $this->addMember($businessLain, RoleSlug::Accountant);

    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '1000000');
    $this->postSimpleJournal($businessLain, $akuntanLain, '1102', '4100', '7777777');

    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    expect($report['total_debit'])->toBe('1000000.00');
    expect($report['business_id'])->toBe($this->business->getKey());
});

it('menjaga presisi sen pada banyak transaksi kecil', function (): void {
    for ($i = 0; $i < 30; $i++) {
        $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '0.10');
    }

    $report = $this->trialBalance->forPeriod($this->business, $this->periodFor($this->business, '2026-01-15'));

    expect($report['total_debit'])->toBe('3.00');
    expect($report['is_balanced'])->toBeTrue();
});

it('menyajikan neraca saldo pada halaman Inertia', function (): void {
    $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '1000000');

    $this->actingAs($this->accountant)
        ->get("/businesses/{$this->business->getKey()}/reports/trial-balance")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/TrialBalance')
            ->where('report.is_balanced', true)
            ->has('report.rows')
        );
});

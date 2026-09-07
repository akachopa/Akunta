<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\JournalEntry;
use App\Services\Accounting\TrialBalanceService;
use App\Support\Money;
use Database\Seeders\GoldenDatasetSeeder;
use Illuminate\Support\Carbon;

/*
 * plan.md §33.3: golden dataset accounting, yaitu test company dengan transaksi yang
 * jawabannya sudah diketahui. Angka pembanding dikunci sebagai literal pada
 * GoldenDatasetSeeder::expectedTrialBalance(), bukan dihitung ulang dari query, supaya
 * setiap perubahan accounting engine langsung terlihat sebagai regresi.
 */

beforeEach(function (): void {
    [$this->owner, $this->business] = $this->provisionBusinessWithOwner('Golden Dataset Retail');

    app(GoldenDatasetSeeder::class)->seedFor($this->owner, $this->business);

    $this->report = app(TrialBalanceService::class)
        ->asOf($this->business, Carbon::parse('2026-01-31'));

    $this->rows = collect($this->report['rows'])->keyBy('code');
    $this->expected = GoldenDatasetSeeder::expectedTrialBalance();
});

it('memposting seluruh transaksi golden dataset', function (): void {
    /*
     * plan.md §33.3: 100 penjualan, 50 pembelian, 20 beban operasional, 5 transaksi
     * pemilik, 3 pinjaman, 10 penerimaan piutang, 10 pembayaran hutang, 1 settlement
     * QRIS, dan 2 transfer internal, ditambah 1 pengakuan HPP.
     */
    $entries = JournalEntry::query()
        ->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())
        ->get();

    expect($entries)->toHaveCount(202);
    expect($entries->every(fn (JournalEntry $entry): bool => $entry->status === JournalEntryStatus::Posted))
        ->toBeTrue();
    expect($entries->every(fn (JournalEntry $entry): bool => $entry->isBalanced()))->toBeTrue();
});

it('menghasilkan trial balance yang seimbang', function (): void {
    expect($this->report['is_balanced'])->toBeTrue();
    expect($this->report['total_debit'])->toBe($this->expected['total']);
    expect($this->report['total_credit'])->toBe($this->expected['total']);
});

it('menghasilkan saldo debit sesuai angka yang dikunci', function (): void {
    foreach ($this->expected['debit'] as $code => $amount) {
        expect($this->rows->has($code))->toBeTrue("Akun {$code} tidak muncul di trial balance");
        expect($this->rows[$code]['debit_balance'])->toBe($amount, "Saldo debit akun {$code} tidak sesuai");
        expect($this->rows[$code]['credit_balance'])->toBe('0.00', "Akun {$code} tidak boleh bersaldo kredit");
    }
});

it('menghasilkan saldo kredit sesuai angka yang dikunci', function (): void {
    foreach ($this->expected['credit'] as $code => $amount) {
        expect($this->rows->has($code))->toBeTrue("Akun {$code} tidak muncul di trial balance");
        expect($this->rows[$code]['credit_balance'])->toBe($amount, "Saldo kredit akun {$code} tidak sesuai");
        expect($this->rows[$code]['debit_balance'])->toBe('0.00', "Akun {$code} tidak boleh bersaldo debit");
    }
});

it('tidak memunculkan akun di luar daftar yang dikunci', function (): void {
    $allowed = array_merge(
        array_keys($this->expected['debit']),
        array_keys($this->expected['credit'])
    );

    $unexpected = $this->rows->keys()->diff($allowed)->values()->all();

    expect($unexpected)->toBe([]);
});

it('menjaga persamaan akuntansi aset sama dengan kewajiban plus ekuitas', function (): void {
    /*
     * plan.md §44.1: jangan pernah melanggar persamaan akuntansi. Ekuitas di sini
     * mencakup laba periode berjalan, karena golden dataset belum melewati closing.
     */
    $sum = function (array $codes): string {
        $total = Money::zero();

        foreach ($codes as $code) {
            $total = Money::add($total, $this->rows[$code]['signed_balance']);
        }

        return $total;
    };

    $assets = $sum(['1101', '1102', '1200', '1300']);
    $liabilities = $sum(['2100', '2300']);
    $equity = $sum(['3100', '3200']);
    $revenue = $sum(['4100']);
    $expenses = $sum(['5100', '6100', '6200', '6300', '6400', '6500', '6600', '6850', '7200']);

    $profit = Money::subtract($revenue, $expenses);
    $rightSide = Money::add(Money::add($liabilities, $equity), $profit);

    expect($assets)->toBe('161980000.00');
    expect(Money::equals($assets, $rightSide))->toBeTrue(
        "Aset {$assets} tidak sama dengan kewajiban + ekuitas {$rightSide}"
    );
});

it('memisahkan gross sales dan mdr pada settlement qris', function (): void {
    /*
     * plan.md §19.2: net settlement tidak boleh dianggap gross revenue. Gross Rp10.000.000
     * masuk pendapatan, MDR Rp70.000 menjadi beban, net Rp9.930.000 masuk bank.
     */
    $entry = JournalEntry::query()
        ->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())
        ->where('description', 'Settlement QRIS harian')
        ->with('lines.account')
        ->sole();

    $byCode = $entry->lines->keyBy(fn ($line): string => $line->account->code);

    expect($byCode['4100']->credit)->toBe('10000000.00');
    expect($byCode['6850']->debit)->toBe('70000.00');
    expect($byCode['1102']->debit)->toBe('9930000.00');
});

it('tidak mencatat transaksi bukan pendapatan sebagai pendapatan', function (): void {
    /*
     * plan.md §44.2–§44.4 dan §10.5: setoran modal, penerimaan pinjaman, penerimaan
     * piutang, dan transfer internal tidak boleh menambah pendapatan. Pendapatan hanya
     * berasal dari 60 penjualan tunai, 40 penjualan kredit, dan settlement QRIS.
     */
    expect($this->rows['4100']['credit_balance'])->toBe('70000000.00');

    $nonRevenue = [
        'Setoran modal pemilik ke bank',
        'Penerimaan pinjaman bank',
        'Penerimaan piutang pelanggan #1',
        'Transfer kas ke bank',
    ];

    $salesAccountId = $this->account($this->business, '4100')->getKey();

    foreach ($nonRevenue as $description) {
        $entry = JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('business_id', $this->business->getKey())
            ->where('description', $description)
            ->with('lines')
            ->sole();

        expect($entry->lines->pluck('account_id'))->not->toContain($salesAccountId);
    }
});

it('tidak mencatat prive dan pembayaran pokok pinjaman sebagai beban', function (): void {
    // plan.md §44.3: prive bukan beban. plan.md §44.4: pokok pinjaman bukan beban.
    expect($this->rows['3200']['debit_balance'])->toBe('15000000.00');
    expect($this->rows['2300']['credit_balance'])->toBe('90000000.00');
    expect($this->rows['7200']['debit_balance'])->toBe('1500000.00');
});

it('mempertahankan trial balance setelah satu entry dibalik', function (): void {
    $entry = JournalEntry::query()
        ->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())
        ->where('description', 'Penjualan tunai #1')
        ->sole();

    app(\App\Services\Accounting\JournalPostingService::class)
        ->reverse($entry, $this->owner, 'Uji dampak reversal pada golden dataset');

    $after = app(TrialBalanceService::class)->asOf($this->business, Carbon::parse('2026-01-31'));

    expect($after['is_balanced'])->toBeTrue();

    // Satu penjualan tunai Rp500.000 hilang dari kedua sisi laporan.
    expect($after['total_debit'])->toBe(Money::subtract($this->expected['total'], '500000'));

    $rows = collect($after['rows'])->keyBy('code');
    expect($rows['4100']['credit_balance'])->toBe('69500000.00');
    expect($rows['1102']['debit_balance'])->toBe('113180000.00');
});

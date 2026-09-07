<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Business\BusinessProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Accounting golden dataset (plan.md §33.3).
 *
 * Test company dengan transaksi yang jawabannya sudah diketahui, mengikuti komposisi
 * pada plan.md §33.3: 100 penjualan, 50 pembelian, 20 beban operasional, 5 transaksi
 * pemilik, 3 transaksi pinjaman, 10 penerimaan piutang, 10 pembayaran hutang, settlement
 * QRIS, dan transfer antar rekening internal.
 *
 * Pada Phase 2 seluruh entry dibuat sebagai manual journal, karena pipeline AI yang
 * menghasilkannya secara otomatis baru dibangun pada Phase 3 ke atas. Dataset ini
 * menjadi acuan pembanding setiap kali accounting engine berubah (plan.md §33.3).
 */
class GoldenDatasetSeeder extends Seeder
{
    public const BUSINESS_NAME = 'Golden Dataset Retail';

    public const OWNER_EMAIL = 'golden-dataset@akunta.test';

    /**
     * Saldo trial balance yang diharapkan, dikunci sebagai angka literal.
     *
     * Nilai ini dihitung manual dari daftar transaksi di bawah, bukan dari hasil query,
     * supaya perubahan pada accounting engine benar-benar terdeteksi.
     *
     * Kode akun numerik menjadi integer key saat PHP membangun array, sehingga tipe
     * key-nya array-key, bukan string.
     *
     * @return array{debit: array<array-key, string>, credit: array<array-key, string>, total: string}
     */
    public static function expectedTrialBalance(): array
    {
        return [
            'debit' => [
                '1101' => '20800000.00',   // Kas
                '1102' => '113680000.00',  // Bank
                '1200' => '22500000.00',   // Piutang usaha
                '1300' => '5000000.00',    // Persediaan
                '3200' => '15000000.00',   // Prive pemilik
                '5100' => '12000000.00',   // Harga pokok penjualan
                '6100' => '10000000.00',   // Beban gaji
                '6200' => '1500000.00',    // Beban utilitas
                '6300' => '6000000.00',    // Beban sewa
                '6400' => '4000000.00',    // Beban pemasaran
                '6500' => '750000.00',     // Beban pengiriman
                '6600' => '1200000.00',    // Beban kantor & administrasi
                '6850' => '70000.00',      // Beban MDR QRIS
                '7200' => '1500000.00',    // Beban bunga
            ],
            'credit' => [
                '2100' => '4000000.00',    // Hutang usaha
                '2300' => '90000000.00',   // Hutang pinjaman
                '3100' => '50000000.00',   // Modal pemilik
                '4100' => '70000000.00',   // Pendapatan penjualan
            ],
            'total' => '214000000.00',
        ];
    }

    public function run(): void
    {
        $this->seedFor($this->resolveOwner(), null);
    }

    /**
     * Menyiapkan dataset pada sebuah business.
     *
     * Bila business tidak diberikan, satu business retail baru dibuat lewat
     * BusinessProvisioningService supaya dataset selalu memakai starter COA yang sama
     * dengan hasil onboarding sebenarnya.
     */
    public function seedFor(User $owner, ?Business $business = null): Business
    {
        /** @var BusinessProvisioningService $provisioning */
        $provisioning = app(BusinessProvisioningService::class);

        $business ??= $provisioning->createBusiness($owner, [
            'name' => self::BUSINESS_NAME,
            'business_type' => BusinessType::Retail->value,
            'opening_date' => '2026-01-01',
        ]);

        /** @var JournalPostingService $posting */
        $posting = app(JournalPostingService::class);

        $accounts = ChartOfAccount::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->getKey())
            ->get()
            ->keyBy('code');

        $account = static fn (string $code): string => $accounts[$code]->getKey();
        $date = Carbon::parse('2026-01-15');

        foreach ($this->entries($account) as $entry) {
            $posting->createAndPost($business, new JournalEntryData(
                entryDate: $date,
                description: $entry['description'],
                lines: $entry['lines'],
            ), $owner);
        }

        return $business;
    }

    /**
     * @param  callable(string): string  $account
     * @return array<int, array{description: string, lines: array<int, JournalLineData>}>
     */
    private function entries(callable $account): array
    {
        $entries = [];

        $cash = $account('1101');
        $bank = $account('1102');
        $receivable = $account('1200');
        $inventory = $account('1300');
        $payable = $account('2100');
        $loan = $account('2300');
        $capital = $account('3100');
        $drawing = $account('3200');
        $sales = $account('4100');
        $cogs = $account('5100');

        // plan.md §33.3: 100 penjualan (60 tunai, 40 kredit).
        for ($i = 1; $i <= 60; $i++) {
            $entries[] = $this->simple("Penjualan tunai #{$i}", $bank, $sales, '500000');
        }

        for ($i = 1; $i <= 40; $i++) {
            $entries[] = $this->simple("Penjualan kredit #{$i}", $receivable, $sales, '750000');
        }

        // plan.md §33.3: 50 pembelian (30 tunai, 20 kredit).
        for ($i = 1; $i <= 30; $i++) {
            $entries[] = $this->simple("Pembelian persediaan tunai #{$i}", $inventory, $bank, '300000');
        }

        for ($i = 1; $i <= 20; $i++) {
            $entries[] = $this->simple("Pembelian persediaan kredit #{$i}", $inventory, $payable, '400000');
        }

        /*
         * Pengakuan HPP tidak disebut eksplisit dalam daftar plan.md §33.3, tetapi
         * expected output-nya memuat laba rugi. Tanpa satu entry HPP, dataset tidak dapat
         * dipakai memvalidasi margin ketika reporting dibangun pada Phase 12.
         */
        $entries[] = $this->simple('Pengakuan harga pokok penjualan periode', $cogs, $inventory, '12000000');

        // plan.md §33.3: 20 beban operasional.
        $operatingExpenses = [
            ['6100', 'Beban gaji', 5, '2000000', $bank],
            ['6200', 'Beban utilitas', 3, '500000', $bank],
            ['6300', 'Beban sewa', 2, '3000000', $bank],
            ['6400', 'Beban pemasaran', 4, '1000000', $bank],
            ['6500', 'Beban pengiriman', 3, '250000', $bank],
            ['6600', 'Beban kantor & administrasi', 3, '400000', $cash],
        ];

        foreach ($operatingExpenses as [$code, $label, $count, $amount, $source]) {
            for ($i = 1; $i <= $count; $i++) {
                $entries[] = $this->simple("{$label} #{$i}", $account($code), $source, $amount);
            }
        }

        /*
         * plan.md §33.3: 5 transaksi pemilik. plan.md §10.5 dan §44 menegaskan setoran
         * modal bukan pendapatan dan prive bukan beban.
         */
        $entries[] = $this->simple('Setoran modal pemilik ke bank', $bank, $capital, '25000000');
        $entries[] = $this->simple('Setoran modal pemilik ke kas', $cash, $capital, '25000000');

        for ($i = 1; $i <= 3; $i++) {
            $entries[] = $this->simple("Prive pemilik #{$i}", $drawing, $bank, '5000000');
        }

        /*
         * plan.md §33.3: 3 transaksi pinjaman. Penerimaan pinjaman bukan pendapatan dan
         * pembayaran pokok bukan beban (plan.md §44.3, §44.4).
         */
        $entries[] = $this->simple('Penerimaan pinjaman bank', $bank, $loan, '100000000');
        $entries[] = $this->simple('Pembayaran pokok pinjaman', $loan, $bank, '10000000');
        $entries[] = $this->simple('Pembayaran bunga pinjaman', $account('7200'), $bank, '1500000');

        // plan.md §33.3: 10 penerimaan piutang. Bukan pendapatan baru.
        for ($i = 1; $i <= 10; $i++) {
            $entries[] = $this->simple("Penerimaan piutang pelanggan #{$i}", $bank, $receivable, '750000');
        }

        // plan.md §33.3: 10 pembayaran hutang. Bukan beban.
        for ($i = 1; $i <= 10; $i++) {
            $entries[] = $this->simple("Pembayaran hutang supplier #{$i}", $payable, $bank, '400000');
        }

        /*
         * plan.md §19.2: settlement QRIS harus memisahkan gross sales, MDR, dan net
         * settlement. Net Rp9.930.000 tidak boleh dianggap gross revenue.
         */
        $entries[] = [
            'description' => 'Settlement QRIS harian',
            'lines' => [
                new JournalLineData(accountId: $bank, debit: '9930000', description: 'Net settlement'),
                new JournalLineData(accountId: $account('6850'), debit: '70000', description: 'MDR'),
                new JournalLineData(accountId: $sales, credit: '10000000', description: 'Gross sales QRIS'),
            ],
        ];

        // plan.md §33.3 dan §10.6: transfer internal tidak boleh masuk P&L.
        $entries[] = $this->simple('Transfer kas ke bank', $bank, $cash, '5000000');
        $entries[] = $this->simple('Transfer bank ke kas', $cash, $bank, '2000000');

        return $entries;
    }

    /**
     * @return array{description: string, lines: array<int, JournalLineData>}
     */
    private function simple(string $description, string $debitAccountId, string $creditAccountId, string $amount): array
    {
        return [
            'description' => $description,
            'lines' => [
                new JournalLineData(accountId: $debitAccountId, debit: $amount),
                new JournalLineData(accountId: $creditAccountId, credit: $amount),
            ],
        ];
    }

    private function resolveOwner(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'name' => 'Golden Dataset Owner',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );
    }
}

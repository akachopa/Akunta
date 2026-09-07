<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Business\Models\Business;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perhitungan trial balance (plan.md §37 Phase 2, §21.1).
 *
 * plan.md §44.5 melarang perhitungan laporan dari raw transaction table dan §45.11
 * mewajibkan seluruh laporan hanya mengambil data dari posted journal. Karena itu query
 * di sini selalu difilter ke journal_entries.status = 'posted'.
 *
 * Reversal entry ikut terhitung karena ia sendiri berstatus posted; entry asli yang
 * berstatus 'reversed' tidak ikut, sehingga pasangan asli+reversal saling menghapus
 * tanpa perlu perlakuan khusus.
 */
class TrialBalanceService
{
    /**
     * @return array{
     *     business_id: string,
     *     from: string,
     *     to: string,
     *     rows: array<int, array<string, mixed>>,
     *     total_debit: string,
     *     total_credit: string,
     *     is_balanced: bool
     * }
     */
    public function forPeriod(Business $business, AccountingPeriod $period): array
    {
        return $this->forDateRange($business, $period->start_date, $period->end_date);
    }

    /**
     * Trial balance kumulatif sejak awal sampai sebuah tanggal.
     *
     * @return array{
     *     business_id: string,
     *     from: string,
     *     to: string,
     *     rows: array<int, array<string, mixed>>,
     *     total_debit: string,
     *     total_credit: string,
     *     is_balanced: bool
     * }
     */
    public function asOf(Business $business, Carbon $asOf): array
    {
        return $this->forDateRange($business, $business->opening_date->copy(), $asOf);
    }

    /**
     * @return array{
     *     business_id: string,
     *     from: string,
     *     to: string,
     *     rows: array<int, array<string, mixed>>,
     *     total_debit: string,
     *     total_credit: string,
     *     is_balanced: bool
     * }
     */
    public function forDateRange(Business $business, Carbon $from, Carbon $to): array
    {
        $movements = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->selectRaw('lines.account_id, SUM(lines.debit) AS total_debit, SUM(lines.credit) AS total_credit')
            ->where('entries.business_id', $business->getKey())
            ->where('entries.status', JournalEntryStatus::Posted->value)
            ->whereDate('entries.entry_date', '>=', $from->toDateString())
            ->whereDate('entries.entry_date', '<=', $to->toDateString())
            ->groupBy('lines.account_id')
            ->get()
            ->keyBy('account_id');

        $accounts = ChartOfAccount::query()
            ->forBusiness($business)
            ->whereIn('id', $movements->keys()->all())
            ->ordered()
            ->get();

        $rows = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();

        foreach ($accounts as $account) {
            $movement = $movements->get($account->getKey());

            $accountDebit = Money::normalize((string) ($movement->total_debit ?? '0'));
            $accountCredit = Money::normalize((string) ($movement->total_credit ?? '0'));

            /*
             * Trial balance menampilkan satu sisi per akun. Sisi ditentukan oleh selisih
             * debit dan kredit akun tersebut, bukan oleh normal balance-nya, supaya akun
             * dengan saldo tidak wajar tetap terlihat apa adanya.
             */
            $net = Money::subtract($accountDebit, $accountCredit);

            $debitBalance = Money::isPositive($net) ? $net : Money::zero();
            $creditBalance = Money::isNegative($net) ? Money::negate($net) : Money::zero();

            $totalDebit = Money::add($totalDebit, $debitBalance);
            $totalCredit = Money::add($totalCredit, $creditBalance);

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                'account_role' => $account->account_role?->value,
                'movement_debit' => $accountDebit,
                'movement_credit' => $accountCredit,
                'debit_balance' => $debitBalance,
                'credit_balance' => $creditBalance,
                'signed_balance' => $this->signedBalance($account->normal_balance, $net),
            ];
        }

        return [
            'business_id' => (string) $business->getKey(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => Money::equals($totalDebit, $totalCredit),
        ];
    }

    /**
     * Saldo akun dalam arah normal balance-nya: positif berarti saldo wajar.
     */
    private function signedBalance(NormalBalance $normalBalance, string $net): string
    {
        return $normalBalance === NormalBalance::Debit ? $net : Money::negate($net);
    }
}

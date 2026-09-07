<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Exceptions\ClosedPeriodViolation;
use App\Domain\Accounting\Exceptions\PeriodNotFound;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Business\Models\Business;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengelolaan accounting period (plan.md §20).
 *
 * Closing readiness score dan checklist (plan.md §20.2, §20.3) adalah Phase 13 dan
 * sengaja tidak diimplementasikan di sini. Yang ada pada Phase 2 hanya lifecycle
 * periode dan period lock, karena keduanya menjadi acceptance criteria Phase 2.
 */
class AccountingPeriodService
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    /**
     * Membuat periode bulanan untuk satu fiscal year.
     *
     * plan.md §2.2 menetapkan accounting period bulanan.
     *
     * @return array<int, AccountingPeriod>
     */
    public function generateMonthlyPeriods(Business $business, int $fiscalYear): array
    {
        $periods = [];

        for ($month = 1; $month <= 12; $month++) {
            $periods[] = $this->ensureMonthlyPeriod($business, $fiscalYear, $month);
        }

        return $periods;
    }

    public function ensureMonthlyPeriod(Business $business, int $fiscalYear, int $month): AccountingPeriod
    {
        $start = Carbon::create($fiscalYear, $month, 1)->startOfDay();

        return AccountingPeriod::query()
            ->forBusiness($business)
            ->where('fiscal_year', $fiscalYear)
            ->where('period_number', $month)
            ->first()
            ?? AccountingPeriod::create([
                'business_id' => $business->getKey(),
                'name' => $start->format('Y-m'),
                'fiscal_year' => $fiscalYear,
                'period_number' => $month,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
                'status' => AccountingPeriodStatus::Open->value,
            ]);
    }

    /**
     * Mencari periode yang memuat sebuah tanggal.
     *
     * @throws PeriodNotFound
     */
    public function periodForDate(Business $business, Carbon $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->forBusiness($business)
            ->containingDate($date)
            ->first();

        if ($period === null) {
            throw PeriodNotFound::forDate($date->toDateString());
        }

        return $period;
    }

    /**
     * @throws ClosedPeriodViolation
     */
    public function assertOpenForJournalActivity(AccountingPeriod $period): void
    {
        if (! $period->acceptsJournalActivity()) {
            throw ClosedPeriodViolation::forPeriod($period);
        }
    }

    public function transitionTo(
        AccountingPeriod $period,
        AccountingPeriodStatus $target,
        User $actor,
        ?string $reason = null,
    ): AccountingPeriod {
        if ($period->status === $target) {
            return $period;
        }

        if (! $period->status->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'Accounting period [%s] berstatus %s tidak dapat berpindah ke %s.',
                $period->name,
                $period->status->value,
                $target->value
            ));
        }

        return match ($target) {
            AccountingPeriodStatus::Closed => $this->close($period, $actor, $reason),
            AccountingPeriodStatus::Reopened => $this->reopen($period, $actor, $reason ?? ''),
            default => $this->setStatus($period, $target, $actor),
        };
    }

    /**
     * plan.md §20.4: setelah CLOSED, journal tidak boleh diubah.
     */
    public function close(AccountingPeriod $period, User $actor, ?string $reason = null): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $actor, $reason): AccountingPeriod {
            $period->update([
                'status' => AccountingPeriodStatus::Closed->value,
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
            ]);

            $this->auditLogger->logChanges('accounting_period.closed', $period, $reason, $period->business, $actor);

            return $period->refresh();
        });
    }

    /**
     * plan.md §20.4: reopen harus dicatat dalam audit log, dan §37 Phase 13 menyatakan
     * reopen memerlukan permission khusus (ditegakkan di policy).
     */
    public function reopen(AccountingPeriod $period, User $actor, string $reason): AccountingPeriod
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Reopen accounting period wajib menyertakan alasan.');
        }

        return DB::transaction(function () use ($period, $actor, $reason): AccountingPeriod {
            $period->update([
                'status' => AccountingPeriodStatus::Reopened->value,
                'reopened_at' => now(),
                'reopened_by' => $actor->getKey(),
                'reopen_reason' => $reason,
            ]);

            $this->auditLogger->logChanges('accounting_period.reopened', $period, $reason, $period->business, $actor);

            return $period->refresh();
        });
    }

    private function setStatus(
        AccountingPeriod $period,
        AccountingPeriodStatus $target,
        User $actor,
    ): AccountingPeriod {
        $period->update(['status' => $target->value]);

        $this->auditLogger->logChanges(
            "accounting_period.{$target->value}",
            $period,
            null,
            $period->business,
            $actor
        );

        return $period->refresh();
    }
}

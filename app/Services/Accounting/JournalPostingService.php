<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Exceptions\InvalidAccountForPosting;
use App\Domain\Accounting\Exceptions\InvalidJournalStateTransition;
use App\Domain\Accounting\Exceptions\UnbalancedJournalEntry;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Business\Models\Business;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Journal posting service (plan.md §37 Phase 2, §11.7, §24.3, §45.5).
 *
 * Aturan yang ditegakkan di sini:
 * - total debit harus sama dengan total kredit, jika tidak posting diblokir (§11.7);
 * - seluruh mutasi journal berjalan dalam satu transaksi database (§45.5);
 * - journal tidak dapat dibuat atau diubah pada periode yang sudah ditutup (§20.4);
 * - posted journal hanya dapat dikoreksi lewat reversal (§44.6);
 * - setiap perubahan status dicatat ke audit trail (§45.6).
 */
class JournalPostingService
{
    public function __construct(
        private readonly AccountingPeriodService $periodService,
        private readonly JournalEntryNumberGenerator $numberGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Membuat journal entry berstatus draft.
     */
    public function createDraft(Business $business, JournalEntryData $data, ?User $actor = null): JournalEntry
    {
        $data->assertBalanced();

        return DB::transaction(fn (): JournalEntry => $this->tenantContext->withBusiness(
            $business,
            function () use ($business, $data, $actor): JournalEntry {
                $period = $this->periodService->periodForDate($business, $data->entryDate);
                $this->periodService->assertOpenForJournalActivity($period);

                $entry = JournalEntry::create([
                    'business_id' => $business->getKey(),
                    'accounting_period_id' => $period->getKey(),
                    'entry_number' => $this->numberGenerator->next($business, $data->entryDate),
                    'entry_date' => $data->entryDate->toDateString(),
                    'description' => $data->description,
                    'source_type' => $data->sourceType,
                    'source_id' => $data->sourceId,
                    'status' => JournalEntryStatus::Draft->value,
                    'currency' => $data->currency,
                    'total_debit' => $data->totalDebit(),
                    'total_credit' => $data->totalCredit(),
                    'created_by' => $actor?->getKey(),
                ]);

                $this->writeLines($business, $entry, $data->lines);
                $this->writeSources($business, $entry, $data);

                $this->auditLogger->log(
                    'journal_entry.created',
                    $entry,
                    null,
                    $this->snapshot($entry),
                    business: $business,
                    actor: $actor
                );

                return $entry->load('lines');
            }
        ));
    }

    /**
     * Mengganti isi journal entry yang masih draft.
     *
     * Baris lama dihapus lalu ditulis ulang, bukan di-update, karena baris journal
     * bersifat append-only (plan.md §44.6).
     */
    public function updateDraft(JournalEntry $entry, JournalEntryData $data, ?User $actor = null): JournalEntry
    {
        $this->assertEditable($entry);
        $data->assertBalanced();

        $business = $entry->business;

        return DB::transaction(fn (): JournalEntry => $this->tenantContext->withBusiness(
            $business,
            function () use ($business, $entry, $data, $actor): JournalEntry {
                $currentPeriod = $entry->accountingPeriod;
                $this->periodService->assertOpenForJournalActivity($currentPeriod);

                $targetPeriod = $this->periodService->periodForDate($business, $data->entryDate);
                $this->periodService->assertOpenForJournalActivity($targetPeriod);

                $entry->lines()->each(static fn (JournalEntryLine $line) => $line->delete());

                $entry->update([
                    'accounting_period_id' => $targetPeriod->getKey(),
                    'entry_date' => $data->entryDate->toDateString(),
                    'description' => $data->description,
                    'currency' => $data->currency,
                    'total_debit' => $data->totalDebit(),
                    'total_credit' => $data->totalCredit(),
                ]);

                $this->writeLines($business, $entry, $data->lines);

                $this->auditLogger->logChanges(
                    'journal_entry.updated',
                    $entry,
                    business: $business,
                    actor: $actor
                );

                return $entry->refresh()->load('lines');
            }
        ));
    }

    public function submitForApproval(JournalEntry $entry, User $actor): JournalEntry
    {
        return $this->transition($entry, JournalEntryStatus::PendingApproval, $actor, [
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
        ]);
    }

    public function approve(JournalEntry $entry, User $actor): JournalEntry
    {
        return $this->transition($entry, JournalEntryStatus::Approved, $actor, [
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Memposting journal entry ke ledger.
     *
     * Approval diperlakukan sebagai bagian dari posting bila entry masih draft atau
     * pending: plan.md §15 menyatakan "pada MVP, journal posting tetap dapat diwajibkan
     * mendapat approval accountant", dan pemisahan izin approve/post ditegakkan di
     * policy, bukan dengan memaksa dua panggilan HTTP.
     */
    public function post(JournalEntry $entry, User $actor): JournalEntry
    {
        if ($entry->status === JournalEntryStatus::Posted) {
            return $entry;
        }

        if (! $entry->status->canTransitionTo(JournalEntryStatus::Posted)
            && ! $entry->status->canTransitionTo(JournalEntryStatus::Approved)) {
            throw InvalidJournalStateTransition::make($entry, JournalEntryStatus::Posted);
        }

        $business = $entry->business;

        return DB::transaction(fn (): JournalEntry => $this->tenantContext->withBusiness(
            $business,
            function () use ($business, $entry, $actor): JournalEntry {
                /*
                 * Baris dibaca ulang di dalam transaksi dan dikunci agar total yang
                 * diverifikasi adalah total yang benar-benar tersimpan, bukan nilai
                 * denormalisasi di header yang bisa tertinggal.
                 */
                $locked = JournalEntry::query()
                    ->whereKey($entry->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status === JournalEntryStatus::Posted) {
                    return $locked;
                }

                $period = $locked->accountingPeriod;
                $this->periodService->assertOpenForJournalActivity($period);

                $this->assertStoredLinesBalanced($locked);

                $updates = [
                    'status' => JournalEntryStatus::Posted->value,
                    'posted_by' => $actor->getKey(),
                    'posted_at' => now(),
                ];

                if ($locked->approved_at === null) {
                    $updates['approved_by'] = $actor->getKey();
                    $updates['approved_at'] = now();
                }

                $locked->update($updates);

                $this->auditLogger->logChanges(
                    'journal_entry.posted',
                    $locked,
                    business: $business,
                    actor: $actor
                );

                return $locked->refresh()->load('lines');
            }
        ));
    }

    /**
     * Membuat draft lalu langsung memposting.
     */
    public function createAndPost(Business $business, JournalEntryData $data, User $actor): JournalEntry
    {
        $entry = $this->createDraft($business, $data, $actor);

        return $this->post($entry, $actor);
    }

    /**
     * Membalik posted journal entry (plan.md §44.6, §25.4 status REVERSED).
     *
     * Entry asli tidak diubah nilainya; yang dibuat adalah entry baru dengan debit dan
     * kredit tertukar, sehingga jejak koreksi tetap utuh (plan.md §31 "jangan overwrite
     * historical decisions").
     */
    public function reverse(
        JournalEntry $entry,
        User $actor,
        string $reason,
        ?Carbon $reversalDate = null,
    ): JournalEntry {
        if ($entry->status !== JournalEntryStatus::Posted) {
            throw InvalidJournalStateTransition::make($entry, JournalEntryStatus::Reversed);
        }

        $business = $entry->business;
        $reversalDate ??= $entry->entry_date->copy();

        return DB::transaction(fn (): JournalEntry => $this->tenantContext->withBusiness(
            $business,
            function () use ($business, $entry, $actor, $reason, $reversalDate): JournalEntry {
                $targetPeriod = $this->periodService->periodForDate($business, $reversalDate);
                $this->periodService->assertOpenForJournalActivity($targetPeriod);

                $entry->load('lines');

                $reversalLines = $entry->lines
                    ->map(static fn (JournalEntryLine $line): JournalLineData => new JournalLineData(
                        accountId: $line->account_id,
                        debit: $line->credit,
                        credit: $line->debit,
                        description: $line->description,
                        entityId: $line->entity_id,
                    ))
                    ->all();

                $reversal = $this->createDraft($business, new JournalEntryData(
                    entryDate: $reversalDate,
                    description: sprintf('Reversal %s — %s', $entry->entry_number, $entry->description),
                    lines: $reversalLines,
                    sourceType: JournalEntry::SOURCE_REVERSAL,
                    sourceId: $entry->getKey(),
                    currency: $entry->currency,
                    sources: [[
                        'source_type' => JournalEntrySource::TYPE_REVERSAL,
                        'source_id' => $entry->getKey(),
                        'reference' => $entry->entry_number,
                    ]],
                ), $actor);

                $reversal->update([
                    'reversal_of_id' => $entry->getKey(),
                    'reversal_reason' => $reason,
                ]);

                $reversal = $this->post($reversal, $actor);

                $entry->update([
                    'status' => JournalEntryStatus::Reversed->value,
                    'reversed_by' => $actor->getKey(),
                    'reversed_at' => now(),
                    'reversed_by_entry_id' => $reversal->getKey(),
                    'reversal_reason' => $reason,
                ]);

                $this->auditLogger->log(
                    'journal_entry.reversed',
                    $entry,
                    ['status' => JournalEntryStatus::Posted->value],
                    [
                        'status' => JournalEntryStatus::Reversed->value,
                        'reversal_entry_number' => $reversal->entry_number,
                    ],
                    $reason,
                    $business,
                    $actor
                );

                return $reversal->refresh()->load('lines');
            }
        ));
    }

    /**
     * @param  array<int, JournalLineData>  $lines
     */
    private function writeLines(Business $business, JournalEntry $entry, array $lines): void
    {
        $accounts = $this->resolveAccounts($business, $lines);

        foreach (array_values($lines) as $index => $line) {
            $account = $accounts[$line->accountId];

            JournalEntryLine::create([
                'journal_entry_id' => $entry->getKey(),
                'business_id' => $business->getKey(),
                'account_id' => $account->getKey(),
                'line_number' => $index + 1,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'entity_id' => $line->entityId,
            ]);
        }
    }

    private function writeSources(Business $business, JournalEntry $entry, JournalEntryData $data): void
    {
        $sources = $data->sources !== [] ? $data->sources : [[
            'source_type' => $data->sourceType,
            'source_id' => $data->sourceId,
        ]];

        foreach ($sources as $source) {
            JournalEntrySource::create([
                'journal_entry_id' => $entry->getKey(),
                'business_id' => $business->getKey(),
                'source_type' => (string) ($source['source_type'] ?? $data->sourceType),
                'source_id' => $source['source_id'] ?? null,
                'reference' => $source['reference'] ?? null,
                'metadata' => $source['metadata'] ?? null,
            ]);
        }
    }

    /**
     * Memastikan seluruh akun tujuan milik bisnis ini dan layak diposting.
     *
     * Pemeriksaan tenant di sini adalah lapisan kedua setelah global scope
     * (plan.md §44.15).
     *
     * @param  array<int, JournalLineData>  $lines
     * @return array<string, ChartOfAccount>
     */
    private function resolveAccounts(Business $business, array $lines): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (JournalLineData $line): string => $line->accountId,
            $lines
        )));

        /** @var array<string, ChartOfAccount> $accounts */
        $accounts = ChartOfAccount::query()
            ->forBusiness($business)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($ids as $id) {
            $account = $accounts[$id] ?? null;

            if ($account === null) {
                throw InvalidAccountForPosting::notFound($id);
            }

            if (! $account->acceptsJournalLines()) {
                throw InvalidAccountForPosting::notPostable($account->code, $account->name);
            }
        }

        return $accounts;
    }

    /**
     * plan.md §11.7: verifikasi balance dilakukan terhadap baris yang tersimpan, bukan
     * terhadap total di header.
     */
    private function assertStoredLinesBalanced(JournalEntry $entry): void
    {
        $totals = DB::table('journal_entry_lines')
            ->selectRaw('COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit')
            ->where('journal_entry_id', $entry->getKey())
            ->first();

        $totalDebit = Money::normalize((string) ($totals->total_debit ?? '0'));
        $totalCredit = Money::normalize((string) ($totals->total_credit ?? '0'));

        if (! Money::equals($totalDebit, $totalCredit)) {
            throw UnbalancedJournalEntry::make($totalDebit, $totalCredit);
        }

        /*
         * Total pada header adalah nilai denormalisasi yang dipakai listing. Diselaraskan
         * di sini supaya header tidak pernah berbeda dari jumlah baris yang diposting.
         */
        if (! Money::equals($entry->total_debit, $totalDebit)
            || ! Money::equals($entry->total_credit, $totalCredit)) {
            $entry->forceFill([
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ])->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(
        JournalEntry $entry,
        JournalEntryStatus $target,
        User $actor,
        array $extra = [],
    ): JournalEntry {
        if (! $entry->status->canTransitionTo($target)) {
            throw InvalidJournalStateTransition::make($entry, $target);
        }

        $business = $entry->business;

        return DB::transaction(fn (): JournalEntry => $this->tenantContext->withBusiness(
            $business,
            function () use ($business, $entry, $target, $actor, $extra): JournalEntry {
                $this->periodService->assertOpenForJournalActivity($entry->accountingPeriod);

                $entry->update(['status' => $target->value, ...$extra]);

                $this->auditLogger->logChanges(
                    "journal_entry.{$target->value}",
                    $entry,
                    business: $business,
                    actor: $actor
                );

                return $entry->refresh();
            }
        ));
    }

    private function assertEditable(JournalEntry $entry): void
    {
        if (! $entry->status->isEditable()) {
            throw InvalidJournalStateTransition::make($entry, JournalEntryStatus::Draft);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(JournalEntry $entry): array
    {
        return [
            'entry_number' => $entry->entry_number,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'status' => $entry->status->value,
            'total_debit' => $entry->total_debit,
            'total_credit' => $entry->total_credit,
        ];
    }
}

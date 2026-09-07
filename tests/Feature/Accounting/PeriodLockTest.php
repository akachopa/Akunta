<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Exceptions\ClosedPeriodViolation;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Enums\RoleSlug;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Carbon;

/*
 * plan.md §37 Phase 2 acceptance: "closed period tidak dapat diubah", dengan aturan
 * period lock pada plan.md §20.4.
 */

beforeEach(function (): void {
    [$this->owner, $this->business] = $this->provisionBusinessWithOwner();
    $this->accountant = $this->addMember($this->business, RoleSlug::Accountant);
    $this->periods = app(AccountingPeriodService::class);
    $this->posting = app(JournalPostingService::class);
    $this->january = $this->periodFor($this->business, '2026-01-15');
});

/**
 * @param  array<int, JournalLineData>  $lines
 */
function periodPayload(array $lines, string $date): JournalEntryData
{
    return new JournalEntryData(
        entryDate: Carbon::parse($date),
        description: 'Journal periode',
        lines: $lines,
    );
}

it('menutup periode dan mencatat penutupnya', function (): void {
    $period = $this->periods->close($this->january, $this->accountant, 'Tutup buku Januari');

    expect($period->status)->toBe(AccountingPeriodStatus::Closed);
    expect($period->closed_by)->toBe($this->accountant->getKey());
    expect($period->closed_at)->not->toBeNull();
    expect($period->acceptsJournalActivity())->toBeFalse();
});

it('menolak journal baru pada periode tertutup', function (): void {
    $this->periods->close($this->january, $this->accountant);

    expect(fn (): JournalEntry => $this->posting->createDraft($this->business, periodPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '100000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '100000'),
    ], '2026-01-20'), $this->owner))->toThrow(ClosedPeriodViolation::class);

    expect(JournalEntry::query()->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())->count())->toBe(0);
});

it('menolak posting draft yang periodenya ditutup setelah draft dibuat', function (): void {
    $draft = $this->posting->createDraft($this->business, periodPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '100000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '100000'),
    ], '2026-01-20'), $this->owner);

    $this->periods->close($this->january, $this->accountant);

    expect(fn (): JournalEntry => $this->posting->post($draft, $this->accountant))
        ->toThrow(ClosedPeriodViolation::class);

    expect($draft->fresh()->status)->toBe(JournalEntryStatus::Draft);
});

it('menolak reversal ke dalam periode tertutup', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000', '2026-01-10');

    $this->periods->close($this->january, $this->accountant);

    expect(fn (): JournalEntry => $this->posting->reverse($entry, $this->accountant, 'Koreksi'))
        ->toThrow(ClosedPeriodViolation::class);

    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Posted);
});

it('mengizinkan reversal pada periode terbuka berikutnya', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000', '2026-01-10');

    $this->periods->close($this->january, $this->accountant);

    $reversal = $this->posting->reverse(
        $entry,
        $this->accountant,
        'Koreksi setelah tutup buku',
        Carbon::parse('2026-02-05')
    );

    expect($reversal->status)->toBe(JournalEntryStatus::Posted);
    expect($reversal->accounting_period_id)
        ->toBe($this->periodFor($this->business, '2026-02-05')->getKey());
    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Reversed);
});

it('mengizinkan journal kembali setelah periode dibuka ulang', function (): void {
    $this->periods->close($this->january, $this->accountant);
    $this->periods->reopen($this->january->refresh(), $this->accountant, 'Faktur terlambat masuk');

    expect($this->january->refresh()->status)->toBe(AccountingPeriodStatus::Reopened);

    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000', '2026-01-25');

    expect($entry->status)->toBe(JournalEntryStatus::Posted);
});

it('mewajibkan alasan saat membuka kembali periode', function (): void {
    $this->periods->close($this->january, $this->accountant);

    expect(fn (): AccountingPeriod => $this->periods->reopen($this->january->refresh(), $this->accountant, '  '))
        ->toThrow(RuntimeException::class);

    expect($this->january->refresh()->status)->toBe(AccountingPeriodStatus::Closed);
});

it('menolak transisi periode yang tidak diizinkan state machine', function (): void {
    $this->periods->close($this->january, $this->accountant);

    expect(fn (): AccountingPeriod => $this->periods->transitionTo(
        $this->january->refresh(),
        AccountingPeriodStatus::Open,
        $this->accountant
    ))->toThrow(RuntimeException::class);
});

it('mengizinkan owner menutup periode tetapi tidak membukanya kembali', function (): void {
    $base = "/businesses/{$this->business->getKey()}/periods/{$this->january->getKey()}";

    $this->actingAs($this->owner)->post("{$base}/close")->assertRedirect();
    expect($this->january->refresh()->status)->toBe(AccountingPeriodStatus::Closed);

    $this->actingAs($this->owner)
        ->post("{$base}/reopen", ['reason' => 'Owner mencoba reopen'])
        ->assertForbidden();

    $this->actingAs($this->accountant)
        ->post("{$base}/reopen", ['reason' => 'Accountant membuka kembali'])
        ->assertRedirect();

    expect($this->january->refresh()->status)->toBe(AccountingPeriodStatus::Reopened);
});

it('menolak staff menutup periode', function (): void {
    $staff = $this->addMember($this->business, RoleSlug::BusinessStaff);

    $this->actingAs($staff)
        ->post("/businesses/{$this->business->getKey()}/periods/{$this->january->getKey()}/close")
        ->assertForbidden();

    expect($this->january->refresh()->status)->toBe(AccountingPeriodStatus::Open);
});

it('menampilkan pelanggaran periode tertutup sebagai validation error di HTTP', function (): void {
    $this->periods->close($this->january, $this->accountant);

    $this->actingAs($this->accountant)
        ->post("/businesses/{$this->business->getKey()}/journals", [
            'entry_date' => '2026-01-20',
            'description' => 'Coba tulis ke periode tertutup',
            'lines' => [
                ['account_id' => $this->account($this->business, '1102')->getKey(), 'debit' => '100000'],
                ['account_id' => $this->account($this->business, '4100')->getKey(), 'credit' => '100000'],
            ],
        ])
        ->assertSessionHasErrors('accounting');
});

it('tidak memengaruhi periode bisnis lain saat menutup periode', function (): void {
    [, $businessLain] = $this->provisionBusinessWithOwner('Bisnis Lain');

    $this->periods->close($this->january, $this->accountant);

    expect($this->periodFor($businessLain, '2026-01-15')->status)
        ->toBe(AccountingPeriodStatus::Open);
});

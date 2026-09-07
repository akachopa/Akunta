<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Exceptions\InvalidJournalStateTransition;
use App\Domain\Accounting\Exceptions\PeriodNotFound;
use App\Domain\Accounting\Exceptions\UnbalancedJournalEntry;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Accounting\Models\JournalEntrySource;
use App\Domain\Business\Enums\RoleSlug;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * plan.md §37 Phase 2 acceptance: "manual journal dapat dipost" dan
 * "debit = credit enforced".
 */

beforeEach(function (): void {
    [$this->owner, $this->business] = $this->provisionBusinessWithOwner();
    $this->accountant = $this->addMember($this->business, RoleSlug::Accountant);
    $this->posting = app(JournalPostingService::class);
});

/**
 * @param  array<int, JournalLineData>  $lines
 */
function journalPayload(array $lines, string $date = '2026-01-15'): JournalEntryData
{
    return new JournalEntryData(
        entryDate: Carbon::parse($date),
        description: 'Journal uji',
        lines: $lines,
    );
}

it('membuat draft journal dengan baris bernomor urut', function (): void {
    $entry = $this->posting->createDraft($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '1500000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '1500000'),
    ]), $this->owner);

    expect($entry->status)->toBe(JournalEntryStatus::Draft);
    expect($entry->total_debit)->toBe('1500000.00');
    expect($entry->total_credit)->toBe('1500000.00');
    expect($entry->lines->pluck('line_number')->all())->toBe([1, 2]);
    expect($entry->accounting_period_id)->toBe($this->periodFor($this->business, '2026-01-15')->getKey());
});

it('memberi nomor entry berurutan per bisnis', function (): void {
    $first = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000');
    $second = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '200000');

    expect($first->entry_number)->not->toBe($second->entry_number);
    expect([$first->entry_number, $second->entry_number])->toBe(
        collect([$first->entry_number, $second->entry_number])->sort()->values()->all()
    );
});

it('memposting journal ke ledger', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '750000');

    expect($entry->status)->toBe(JournalEntryStatus::Posted);
    expect($entry->posted_at)->not->toBeNull();
    expect($entry->posted_by)->toBe($this->accountant->getKey());
    expect($entry->approved_at)->not->toBeNull();
});

it('menolak journal yang tidak seimbang', function (): void {
    expect(fn (): JournalEntry => $this->posting->createDraft($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '1000000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '999999.99'),
    ]), $this->owner))->toThrow(UnbalancedJournalEntry::class);

    expect(JournalEntry::query()->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())->count())->toBe(0);
});

it('menolak posting ketika baris tersimpan diubah menjadi tidak seimbang', function (): void {
    $entry = $this->posting->createDraft($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '500000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '500000'),
    ]), $this->owner);

    /*
     * plan.md §11.7: verifikasi balance harus dilakukan terhadap baris yang tersimpan,
     * bukan terhadap total denormalisasi di header. Baris diubah lewat query mentah
     * untuk membuktikan header saja tidak dipercaya.
     */
    DB::table('journal_entry_lines')
        ->where('journal_entry_id', $entry->getKey())
        ->where('credit', '>', 0)
        ->update(['credit' => 400000]);

    expect(fn (): JournalEntry => $this->posting->post($entry, $this->accountant))
        ->toThrow(UnbalancedJournalEntry::class);

    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
});

it('membatalkan seluruh mutasi ketika posting gagal', function (): void {
    $before = JournalEntry::query()->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())->count();

    try {
        $this->posting->createDraft($this->business, journalPayload([
            new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '100000'),
            new JournalLineData(accountId: '11111111-1111-1111-1111-111111111111', credit: '100000'),
        ]), $this->owner);
    } catch (Throwable) {
        // Kegagalan memang yang diharapkan; yang diuji adalah rollback-nya.
    }

    expect(JournalEntry::query()->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())->count())->toBe($before);

    expect(JournalEntryLine::query()->withoutGlobalScopes()
        ->where('business_id', $this->business->getKey())->count())->toBe(0);
});

it('menolak journal pada tanggal tanpa accounting period', function (): void {
    expect(fn (): JournalEntry => $this->posting->createDraft($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '100000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '100000'),
    ], '2030-06-01'), $this->owner))->toThrow(PeriodNotFound::class);
});

it('mengikuti state machine draft ke pending ke approved ke posted', function (): void {
    $entry = $this->posting->createDraft($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '100000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '100000'),
    ]), $this->owner);

    $entry = $this->posting->submitForApproval($entry, $this->owner);
    expect($entry->status)->toBe(JournalEntryStatus::PendingApproval);

    $entry = $this->posting->approve($entry, $this->owner);
    expect($entry->status)->toBe(JournalEntryStatus::Approved);
    expect($entry->approved_by)->toBe($this->owner->getKey());

    $entry = $this->posting->post($entry, $this->accountant);
    expect($entry->status)->toBe(JournalEntryStatus::Posted);
});

it('menolak menyetujui journal yang sudah diposting', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000');

    expect(fn (): JournalEntry => $this->posting->approve($entry, $this->accountant))
        ->toThrow(InvalidJournalStateTransition::class);
});

it('mengabaikan posting berulang pada entry yang sudah posted', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000');
    $postedAt = $entry->posted_at;

    $again = $this->posting->post($entry, $this->accountant);

    expect($again->status)->toBe(JournalEntryStatus::Posted);
    expect($again->posted_at->toIso8601String())->toBe($postedAt->toIso8601String());
});

it('memperbarui draft dengan menulis ulang seluruh baris', function (): void {
    $entry = $this->posting->createDraft($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '100000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '100000'),
    ]), $this->owner);

    $entry = $this->posting->updateDraft($entry, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1101')->getKey(), debit: '250000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '250000'),
    ]), $this->owner);

    expect($entry->lines)->toHaveCount(2);
    expect($entry->total_debit)->toBe('250000.00');
    expect($entry->lines->firstWhere('line_number', 1)->account->code)->toBe('1101');
});

it('mencatat sumber journal untuk keterlacakan', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '100000');

    $source = JournalEntrySource::query()->withoutGlobalScopes()
        ->where('journal_entry_id', $entry->getKey())
        ->sole();

    expect($source->source_type)->toBe(JournalEntry::SOURCE_MANUAL);
});

it('mendukung journal multi-baris seperti settlement QRIS', function (): void {
    $entry = $this->posting->createAndPost($this->business, journalPayload([
        new JournalLineData(accountId: $this->account($this->business, '1102')->getKey(), debit: '9930000'),
        new JournalLineData(accountId: $this->account($this->business, '6850')->getKey(), debit: '70000'),
        new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '10000000'),
    ]), $this->accountant);

    expect($entry->lines)->toHaveCount(3);
    expect($entry->total_debit)->toBe('10000000.00');
    expect($entry->isBalanced())->toBeTrue();
});

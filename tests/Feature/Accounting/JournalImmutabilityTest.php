<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Exceptions\ImmutablePostedJournal;
use App\Domain\Accounting\Exceptions\InvalidJournalStateTransition;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Enums\RoleSlug;
use App\Services\Accounting\JournalPostingService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/*
 * plan.md §40 "immutable posted journal data" dan §44.6 "jangan mengubah posted journal
 * tanpa reversal".
 */

beforeEach(function (): void {
    [$this->owner, $this->business] = $this->provisionBusinessWithOwner();
    $this->accountant = $this->addMember($this->business, RoleSlug::Accountant);
    $this->posting = app(JournalPostingService::class);
    $this->entry = $this->postSimpleJournal($this->business, $this->accountant, '1102', '4100', '1000000');
});

it('menolak mengubah nilai posted journal', function (): void {
    expect(fn (): bool => $this->entry->update(['description' => 'Diubah diam-diam']))
        ->toThrow(ImmutablePostedJournal::class);

    expect(fn (): bool => $this->entry->update(['total_debit' => '5000000']))
        ->toThrow(ImmutablePostedJournal::class);

    expect($this->entry->fresh()->description)->not->toBe('Diubah diam-diam');
});

it('menolak menghapus posted journal', function (): void {
    expect(fn (): ?bool => $this->entry->delete())->toThrow(ImmutablePostedJournal::class);

    expect(JournalEntry::query()->withoutGlobalScopes()->whereKey($this->entry->getKey())->exists())
        ->toBeTrue();
});

it('menolak memperbarui draft yang sudah diposting', function (): void {
    expect(fn (): JournalEntry => $this->posting->updateDraft($this->entry, new JournalEntryData(
        entryDate: Carbon::parse('2026-01-15'),
        description: 'Coba ubah',
        lines: [
            new JournalLineData(accountId: $this->account($this->business, '1101')->getKey(), debit: '1'),
            new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '1'),
        ],
    ), $this->owner))->toThrow(InvalidJournalStateTransition::class);
});

it('mengizinkan penandaan reversal pada posted journal', function (): void {
    $reversal = $this->posting->reverse($this->entry, $this->accountant, 'Salah akun');

    $this->entry->refresh();

    expect($this->entry->status)->toBe(JournalEntryStatus::Reversed);
    expect($this->entry->reversed_by_entry_id)->toBe($reversal->getKey());
    expect($this->entry->reversal_reason)->toBe('Salah akun');
    expect($this->entry->total_debit)->toBe('1000000.00');
});

it('membuat reversal dengan debit dan kredit tertukar', function (): void {
    $reversal = $this->posting->reverse($this->entry, $this->accountant, 'Salah akun');

    expect($reversal->status)->toBe(JournalEntryStatus::Posted);
    expect($reversal->reversal_of_id)->toBe($this->entry->getKey());
    expect($reversal->source_type)->toBe(JournalEntry::SOURCE_REVERSAL);

    $original = $this->entry->lines->keyBy('account_id');

    foreach ($reversal->lines as $line) {
        expect(Money::normalize($line->debit))->toBe(Money::normalize($original[$line->account_id]->credit));
        expect(Money::normalize($line->credit))->toBe(Money::normalize($original[$line->account_id]->debit));
    }
});

it('menolak membalik entry yang belum diposting', function (): void {
    $draft = $this->posting->createDraft($this->business, new JournalEntryData(
        entryDate: Carbon::parse('2026-01-20'),
        description: 'Draft',
        lines: [
            new JournalLineData(accountId: $this->account($this->business, '1101')->getKey(), debit: '5000'),
            new JournalLineData(accountId: $this->account($this->business, '4100')->getKey(), credit: '5000'),
        ],
    ), $this->owner);

    expect(fn (): JournalEntry => $this->posting->reverse($draft, $this->accountant, 'Alasan'))
        ->toThrow(InvalidJournalStateTransition::class);
});

it('menolak membalik entry yang sudah dibalik', function (): void {
    $this->posting->reverse($this->entry, $this->accountant, 'Salah akun');

    expect(fn (): JournalEntry => $this->posting->reverse($this->entry->refresh(), $this->accountant, 'Lagi'))
        ->toThrow(InvalidJournalStateTransition::class);
});

it('membalik lewat HTTP dan menolak aktor tanpa permission reverse', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1101', '4100', '50000');
    $base = "/businesses/{$this->business->getKey()}/journals/{$entry->getKey()}";

    $this->actingAs($this->owner)
        ->post("{$base}/reverse", ['reason' => 'Owner tidak berhak'])
        ->assertForbidden();

    $this->actingAs($this->accountant)
        ->post("{$base}/reverse", ['reason' => 'Salah pencatatan'])
        ->assertRedirect();

    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Reversed);
});

it('mewajibkan alasan pada reversal lewat HTTP', function (): void {
    $entry = $this->postSimpleJournal($this->business, $this->accountant, '1101', '4100', '50000');

    $this->actingAs($this->accountant)
        ->post("/businesses/{$this->business->getKey()}/journals/{$entry->getKey()}/reverse", [])
        ->assertSessionHasErrors('reason');

    expect($entry->fresh()->status)->toBe(JournalEntryStatus::Posted);
});

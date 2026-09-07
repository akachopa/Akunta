<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Exceptions\InvalidJournalLine;
use App\Domain\Accounting\Exceptions\UnbalancedJournalEntry;
use Illuminate\Support\Carbon;

/*
 * plan.md §33.1 mewajibkan unit test journal balancing. Test di sini menguji validasi
 * payload sebelum menyentuh database.
 */

function lineData(string $debit = '0', string $credit = '0'): JournalLineData
{
    return new JournalLineData(
        accountId: '11111111-1111-1111-1111-111111111111',
        debit: $debit,
        credit: $credit,
    );
}

function entryData(JournalLineData ...$lines): JournalEntryData
{
    return new JournalEntryData(
        entryDate: Carbon::parse('2026-01-15'),
        description: 'Uji',
        lines: $lines,
    );
}

it('menolak baris yang mengisi debit dan kredit sekaligus', function (): void {
    expect(fn (): JournalLineData => lineData('100', '100'))
        ->toThrow(InvalidJournalLine::class);
});

it('menolak baris tanpa nilai', function (): void {
    expect(fn (): JournalLineData => lineData('0', '0'))
        ->toThrow(InvalidJournalLine::class);
});

it('menolak baris bernilai negatif', function (): void {
    expect(fn (): JournalLineData => lineData('-100', '0'))
        ->toThrow(InvalidJournalLine::class);

    expect(fn (): JournalLineData => lineData('0', '-100'))
        ->toThrow(InvalidJournalLine::class);
});

it('menolak journal dengan kurang dari dua baris', function (): void {
    expect(fn (): JournalEntryData => entryData(lineData('100')))
        ->toThrow(InvalidArgumentException::class);
});

it('menghitung total debit dan kredit dari baris', function (): void {
    $entry = entryData(
        lineData('9930000'),
        lineData('70000'),
        lineData('0', '10000000'),
    );

    expect($entry->totalDebit())->toBe('10000000.00');
    expect($entry->totalCredit())->toBe('10000000.00');
    expect($entry->isBalanced())->toBeTrue();
});

it('memblokir journal yang tidak seimbang', function (): void {
    $entry = entryData(lineData('100.00'), lineData('0', '99.99'));

    expect($entry->isBalanced())->toBeFalse();
    expect(fn () => $entry->assertBalanced())->toThrow(UnbalancedJournalEntry::class);
});

it('mengenali selisih satu sen sebagai tidak seimbang', function (): void {
    $entry = entryData(lineData('1000000.00'), lineData('0', '1000000.01'));

    expect($entry->isBalanced())->toBeFalse();
});

it('membangun payload dari array request', function (): void {
    $entry = JournalEntryData::fromArray([
        'entry_date' => '2026-02-01',
        'description' => 'Penjualan tunai',
        'lines' => [
            ['account_id' => 'a', 'debit' => '500000'],
            ['account_id' => 'b', 'credit' => 500000],
        ],
    ]);

    expect($entry->entryDate->toDateString())->toBe('2026-02-01');
    expect($entry->totalDebit())->toBe('500000.00');
    expect($entry->totalCredit())->toBe('500000.00');
});

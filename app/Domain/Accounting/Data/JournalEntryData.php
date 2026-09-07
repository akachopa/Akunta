<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Exceptions\UnbalancedJournalEntry;
use App\Domain\Accounting\Models\JournalEntry;
use App\Support\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Payload journal entry beserta barisnya.
 */
final class JournalEntryData
{
    /**
     * @param  array<int, JournalLineData>  $lines
     * @param  array<int, array<string, mixed>>  $sources
     */
    public function __construct(
        public readonly Carbon $entryDate,
        public readonly string $description,
        public readonly array $lines,
        public readonly string $sourceType = JournalEntry::SOURCE_MANUAL,
        public readonly ?string $sourceId = null,
        public readonly string $currency = 'IDR',
        public readonly array $sources = [],
    ) {
        if ($this->lines === []) {
            throw new InvalidArgumentException('Journal entry harus memiliki minimal satu baris.');
        }

        if (count($this->lines) < 2) {
            throw new InvalidArgumentException(
                'Journal entry double-entry harus memiliki minimal dua baris.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<int, array<string, mixed>> $rawLines */
        $rawLines = $payload['lines'] ?? [];

        return new self(
            entryDate: Carbon::parse((string) $payload['entry_date'])->startOfDay(),
            description: (string) $payload['description'],
            lines: array_map(
                static fn (array $line): JournalLineData => JournalLineData::fromArray($line),
                array_values($rawLines)
            ),
            sourceType: (string) ($payload['source_type'] ?? JournalEntry::SOURCE_MANUAL),
            sourceId: isset($payload['source_id']) ? (string) $payload['source_id'] : null,
            currency: (string) ($payload['currency'] ?? 'IDR'),
            sources: array_values($payload['sources'] ?? []),
        );
    }

    public function totalDebit(): string
    {
        return Money::sum(array_map(
            static fn (JournalLineData $line): string => $line->debit,
            $this->lines
        ));
    }

    public function totalCredit(): string
    {
        return Money::sum(array_map(
            static fn (JournalLineData $line): string => $line->credit,
            $this->lines
        ));
    }

    public function isBalanced(): bool
    {
        return Money::equals($this->totalDebit(), $this->totalCredit());
    }

    /**
     * plan.md §11.7: SUM(DEBIT) == SUM(CREDIT), jika tidak maka posting diblokir.
     *
     * @throws UnbalancedJournalEntry
     */
    public function assertBalanced(): void
    {
        if (! $this->isBalanced()) {
            throw UnbalancedJournalEntry::make($this->totalDebit(), $this->totalCredit());
        }
    }
}

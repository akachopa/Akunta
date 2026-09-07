<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Models\JournalEntry;

/**
 * Pelanggaran state machine journal pada plan.md §25.4.
 */
final class InvalidJournalStateTransition extends AccountingException
{
    public static function make(JournalEntry $entry, JournalEntryStatus $target): self
    {
        return new self(sprintf(
            'Journal entry [%s] berstatus %s tidak dapat berpindah ke %s.',
            $entry->entry_number,
            $entry->status->value,
            $target->value
        ));
    }
}

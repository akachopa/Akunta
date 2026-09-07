<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalEntryLine;

/**
 * plan.md §44.6: posted journal tidak boleh diubah tanpa reversal.
 * plan.md §40: posted journal data bersifat immutable.
 */
final class ImmutablePostedJournal extends AccountingException
{
    /**
     * @param  array<int, string>  $fields
     */
    public static function forFields(JournalEntry $entry, array $fields): self
    {
        return new self(sprintf(
            'Journal entry [%s] sudah diposting sehingga tidak dapat diubah (kolom: %s). Gunakan reversal.',
            $entry->entry_number,
            implode(', ', $fields)
        ));
    }

    public static function forDeletion(JournalEntry $entry): self
    {
        return new self(sprintf(
            'Journal entry [%s] sudah diposting sehingga tidak dapat dihapus. Gunakan reversal.',
            $entry->entry_number
        ));
    }

    public static function forLineMutation(JournalEntryLine $line): self
    {
        return new self(sprintf(
            'Baris journal [%s] bersifat immutable. Koreksi dilakukan dengan reversal entry, bukan mengubah baris.',
            $line->getKey() ?? 'baru'
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

/**
 * plan.md §11.7: jika SUM(DEBIT) != SUM(CREDIT) maka posting harus diblokir.
 */
final class UnbalancedJournalEntry extends AccountingException
{
    private function __construct(
        string $message,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
    ) {
        parent::__construct($message);
    }

    public static function make(string $totalDebit, string $totalCredit): self
    {
        return new self(
            sprintf(
                'Journal entry tidak balance: total debit %s, total kredit %s. Posting diblokir.',
                $totalDebit,
                $totalCredit
            ),
            $totalDebit,
            $totalCredit
        );
    }
}

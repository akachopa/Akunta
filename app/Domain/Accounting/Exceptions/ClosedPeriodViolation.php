<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use App\Domain\Accounting\Models\AccountingPeriod;

/**
 * plan.md §20.4: setelah period CLOSED, journal tidak boleh diubah dan perubahan
 * membutuhkan reopen.
 */
final class ClosedPeriodViolation extends AccountingException
{
    public static function forPeriod(AccountingPeriod $period): self
    {
        return new self(sprintf(
            'Accounting period [%s] sudah ditutup. Perubahan journal memerlukan reopen periode terlebih dahulu.',
            $period->name
        ));
    }

    public static function forDate(string $date): self
    {
        return new self(sprintf(
            'Tanggal [%s] berada pada accounting period yang sudah ditutup.',
            $date
        ));
    }
}

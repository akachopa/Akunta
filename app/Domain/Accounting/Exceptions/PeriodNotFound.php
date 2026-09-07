<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

/**
 * Tidak ada accounting period yang memuat tanggal entry.
 *
 * plan.md §24.2 mewajibkan setiap journal entry terikat ke satu accounting_period_id,
 * sehingga tanggal di luar periode yang terdaftar tidak boleh diposting.
 */
final class PeriodNotFound extends AccountingException
{
    public static function forDate(string $date): self
    {
        return new self(
            "Tidak ada accounting period yang memuat tanggal [{$date}]. Buat periode tersebut lebih dahulu."
        );
    }
}

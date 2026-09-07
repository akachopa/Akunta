<?php

declare(strict_types=1);

namespace App\Domain\Review\Exceptions;

use App\Domain\Transactions\Models\Transaction;

final class ReviewNotAllowed extends ReviewException
{
    public static function forTransaction(Transaction $transaction, string $action): self
    {
        return new self(sprintf(
            'Transaksi %s berstatus %s tidak dapat %s.',
            $transaction->reference,
            $transaction->status->label(),
            $action
        ));
    }

    public static function missingJournal(Transaction $transaction): self
    {
        return new self(sprintf(
            'Transaksi %s tidak memiliki jurnal draft yang dapat diposting.',
            $transaction->reference
        ));
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan wajib diisi ketika menolak transaksi.');
    }
}

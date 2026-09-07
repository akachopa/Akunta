<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Exceptions;

use App\Domain\Transactions\Enums\TransactionStatus;

/**
 * plan.md §25.2: status transaksi hanya boleh bergerak mengikuti state machine.
 */
final class InvalidTransactionTransition extends TransactionException
{
    public static function between(TransactionStatus $from, TransactionStatus $to): self
    {
        return new self(sprintf(
            'Transaksi tidak dapat berpindah dari status [%s] ke [%s].',
            $from->value,
            $to->value
        ));
    }
}

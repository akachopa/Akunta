<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

use App\Domain\Documents\Enums\DocumentStatus;

/**
 * plan.md §25.1: status dokumen hanya boleh bergerak mengikuti state machine.
 */
final class InvalidDocumentTransition extends DocumentException
{
    public static function between(DocumentStatus $from, DocumentStatus $to): self
    {
        return new self(sprintf(
            'Dokumen tidak dapat berpindah dari status [%s] ke [%s].',
            $from->value,
            $to->value
        ));
    }
}

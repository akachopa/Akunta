<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

/**
 * Pelanggaran constraint baris journal pada plan.md §24.3.
 */
final class InvalidJournalLine extends AccountingException {}

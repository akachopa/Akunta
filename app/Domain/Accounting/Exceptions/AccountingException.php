<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/**
 * Basis exception domain akuntansi.
 *
 * Semua pelanggaran aturan akuntansi diangkat sebagai turunan kelas ini agar HTTP layer
 * dapat memetakannya menjadi 422 tanpa mengenali setiap kasus satu per satu.
 */
abstract class AccountingException extends RuntimeException
{
}

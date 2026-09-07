<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

use RuntimeException;

/**
 * Basis exception domain dokumen.
 *
 * Seperti AccountingException, semua pelanggaran aturan domain dokumen diangkat sebagai
 * turunan kelas ini agar HTTP layer dapat memetakannya menjadi 422 tanpa mengenali setiap
 * kasus satu per satu.
 */
abstract class DocumentException extends RuntimeException {}

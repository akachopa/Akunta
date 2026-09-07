<?php

declare(strict_types=1);

namespace App\Domain\Review\Exceptions;

use RuntimeException;

/**
 * Basis exception Review Center.
 *
 * Pelanggaran aturan review adalah kesalahan yang dapat diperbaiki user (plan.md §16),
 * jadi HTTP layer memetakannya menjadi 422.
 */
abstract class ReviewException extends RuntimeException {}

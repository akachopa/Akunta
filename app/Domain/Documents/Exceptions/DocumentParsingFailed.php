<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

/**
 * Berkas seharusnya dapat diparse tetapi gagal, misalnya karena korup atau karena AI
 * worker tidak dapat dihubungi.
 *
 * plan.md §37 Phase 3 acceptance: kegagalan seperti ini harus dapat diretry.
 */
final class DocumentParsingFailed extends DocumentException
{
    public static function workerUnreachable(string $detail): self
    {
        return new self('AI worker tidak dapat dihubungi untuk memparse dokumen: ' . $detail);
    }

    public static function workerRejected(int $status, string $detail): self
    {
        return new self(sprintf('AI worker menolak berkas (HTTP %d): %s', $status, $detail));
    }

    public static function missingOriginalFile(): self
    {
        return new self('Berkas asli dokumen tidak ditemukan pada storage.');
    }
}

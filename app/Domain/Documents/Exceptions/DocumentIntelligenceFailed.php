<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

/**
 * Klasifikasi atau ekstraksi gagal karena sebab yang dapat pulih sendiri.
 *
 * Dipisahkan dari output yang ditolak validasi. Kegagalan di kelas ini — worker tidak dapat
 * dihubungi, provider belum dikonfigurasi, provider merespons error — layak diretry queue,
 * dan dokumen belum boleh ditandai gagal sebelum seluruh percobaan habis. Output yang
 * ditolak validasi justru sebaliknya: mengulanginya menghasilkan penolakan yang sama, jadi
 * ia dicatat sebagai hasil dan diserahkan ke reviewer, bukan dilempar sebagai exception.
 */
final class DocumentIntelligenceFailed extends DocumentException
{
    public static function workerUnreachable(string $task, string $detail): self
    {
        return new self(sprintf(
            'AI worker tidak dapat dihubungi untuk tahap %s: %s',
            $task,
            $detail
        ));
    }

    public static function providerUnavailable(string $task, string $detail): self
    {
        return new self(sprintf(
            'Provider AI belum siap untuk tahap %s: %s',
            $task,
            $detail
        ));
    }

    public static function workerRejected(string $task, int $status, string $detail): self
    {
        return new self(sprintf(
            'AI worker menolak permintaan tahap %s (HTTP %d): %s',
            $task,
            $status,
            $detail
        ));
    }
}

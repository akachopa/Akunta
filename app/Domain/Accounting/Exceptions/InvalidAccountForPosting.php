<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

/**
 * Akun tujuan tidak layak menerima journal line: berada di tenant lain, tidak aktif,
 * atau merupakan akun header (bukan postable).
 */
final class InvalidAccountForPosting extends AccountingException
{
    public static function notFound(string $accountId): self
    {
        return new self("Akun [{$accountId}] tidak ditemukan pada chart of accounts bisnis ini.");
    }

    public static function notPostable(string $code, string $name): self
    {
        return new self(
            "Akun [{$code} {$name}] tidak dapat menerima journal entry karena tidak aktif atau merupakan akun header."
        );
    }
}

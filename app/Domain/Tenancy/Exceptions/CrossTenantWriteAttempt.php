<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class CrossTenantWriteAttempt extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(
            "Percobaan menyimpan [{$model}] milik business lain pada tenant context aktif."
        );
    }
}

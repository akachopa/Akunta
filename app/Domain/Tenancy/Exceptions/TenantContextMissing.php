<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class TenantContextMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Tidak ada business aktif pada tenant context. '
            .'Operasi bertenant harus dijalankan di dalam TenantContext::withBusiness().'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Business\Models\Business;
use App\Domain\Tenancy\Exceptions\TenantContextMissing;
use Closure;

/**
 * Menyimpan business yang sedang aktif untuk request/job saat ini.
 *
 * plan.md §30 mewajibkan tenant isolation dan §44.15 melarang cross-tenant access.
 * Context ini adalah satu-satunya sumber kebenaran untuk global scope tenant, sehingga
 * query model yang bertenant tidak pernah bergantung pada parameter route secara ad hoc.
 *
 * Dibind sebagai singleton (scoped per request) di AppServiceProvider.
 */
final class TenantContext
{
    private ?Business $business = null;

    /**
     * Ketika true, global scope tenant dilewati. Hanya boleh dinyalakan melalui
     * withoutTenant() untuk keperluan seeding, migrasi data, dan platform admin.
     */
    private bool $suppressed = false;

    public function set(Business $business): void
    {
        $this->business = $business;
    }

    public function forget(): void
    {
        $this->business = null;
    }

    public function has(): bool
    {
        return $this->business instanceof Business;
    }

    public function business(): ?Business
    {
        return $this->business;
    }

    public function businessId(): ?string
    {
        return $this->business?->getKey();
    }

    /**
     * @throws TenantContextMissing
     */
    public function businessIdOrFail(): string
    {
        $id = $this->businessId();

        if ($id === null) {
            throw new TenantContextMissing;
        }

        return $id;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    /**
     * Menentukan apakah global scope tenant harus diterapkan pada query saat ini.
     */
    public function shouldScopeQueries(): bool
    {
        return ! $this->suppressed && $this->has();
    }

    /**
     * Jalankan callback tanpa filter tenant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenant(Closure $callback): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;

        try {
            return $callback();
        } finally {
            $this->suppressed = $previous;
        }
    }

    /**
     * Jalankan callback dengan business tertentu sebagai tenant aktif.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withBusiness(Business $business, Closure $callback): mixed
    {
        $previousBusiness = $this->business;
        $previousSuppressed = $this->suppressed;

        $this->business = $business;
        $this->suppressed = false;

        try {
            return $callback();
        } finally {
            $this->business = $previousBusiness;
            $this->suppressed = $previousSuppressed;
        }
    }
}

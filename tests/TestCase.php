<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles, permissions, dan COA template adalah data referensi (plan.md §4, §12.1),
     * bukan data uji, jadi selalu di-seed bersama migrasi test database.
     */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Test backend tidak boleh bergantung pada hasil `npm run build`. Kebenaran
         * bundel frontend diverifikasi terpisah oleh `npm run build` di CI.
         */
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        /*
         * TenantContext adalah singleton scoped. Dibersihkan eksplisit supaya tenant
         * aktif dari satu test tidak pernah bocor ke test berikutnya dan menyamarkan
         * kebocoran isolasi (plan.md §44.15).
         */
        if ($this->app !== null) {
            $this->app->make(TenantContext::class)->forget();
        }

        parent::tearDown();
    }
}

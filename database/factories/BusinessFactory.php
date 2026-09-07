<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Business\Enums\AccountingBasis;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory ini membuat baris `businesses` saja, tanpa COA maupun accounting period.
 *
 * Untuk test yang membutuhkan bisnis siap pakai, gunakan
 * Tests\Support\ProvisionsBusinesses yang memanggil BusinessProvisioningService, agar
 * yang diuji adalah jalur onboarding sebenarnya.
 *
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'legal_name' => $name,
            'business_type' => BusinessType::Retail->value,
            'currency' => 'IDR',
            'accounting_basis' => AccountingBasis::Accrual->value,
            'period_length' => 'monthly',
            'opening_date' => now()->startOfYear()->toDateString(),
            'fiscal_year_start_month' => '01',
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ];
    }

    public function ofType(BusinessType $type): static
    {
        return $this->state(fn (): array => ['business_type' => $type->value]);
    }
}

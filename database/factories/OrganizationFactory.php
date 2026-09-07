<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Business\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'type' => Organization::TYPE_BUSINESS,
            'owner_id' => User::factory(),
        ];
    }

    public function accountingFirm(): static
    {
        return $this->state(fn (): array => ['type' => Organization::TYPE_ACCOUNTING_FIRM]);
    }
}

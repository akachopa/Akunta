<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Models\CoaTemplate;
use App\Domain\Accounting\Support\StarterCoaDefinition;
use App\Domain\Business\Enums\BusinessType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder COA template per jenis usaha (plan.md §5.1, §12.1).
 */
class CoaTemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (BusinessType::cases() as $businessType) {
                $template = CoaTemplate::query()->updateOrCreate(
                    ['code' => 'starter-' . $businessType->value],
                    [
                        'name' => 'Starter COA — ' . $businessType->label(),
                        'business_type' => $businessType->value,
                        'description' => 'Chart of accounts starter untuk ' . $businessType->label() . '.',
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );

                foreach (StarterCoaDefinition::for($businessType) as $account) {
                    $template->accounts()->updateOrCreate(
                        ['code' => $account['code']],
                        $account
                    );
                }
            }
        });
    }
}

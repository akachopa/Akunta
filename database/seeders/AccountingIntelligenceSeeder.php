<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Enums\EconomicEventCode;
use App\Domain\Accounting\Models\AccountingRule;
use App\Domain\Accounting\Models\AccountingRuleLine;
use App\Domain\Accounting\Models\EconomicEventType;
use App\Domain\Accounting\Support\AccountingRuleDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Taksonomi economic event dan accounting rule (plan.md §10, §11, §37 Phase 8–9).
 */
class AccountingIntelligenceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (EconomicEventCode::cases() as $event) {
                $type = EconomicEventType::query()->updateOrCreate(
                    ['code' => $event->value],
                    [
                        'category' => $event->category(),
                        'name' => $event->label(),
                        'is_active' => true,
                    ]
                );

                $rule = AccountingRule::query()->updateOrCreate(
                    ['event_type_id' => $type->getKey()],
                    [
                        'name' => $event->label(),
                        'description' => sprintf('Rule otomatis untuk %s.', $event->value),
                        'is_active' => true,
                    ]
                );

                $rule->lines()->delete();

                foreach (AccountingRuleDefinition::lines($event) as $index => $line) {
                    AccountingRuleLine::query()->create([
                        'rule_id' => $rule->getKey(),
                        'line_number' => $index + 1,
                        'side' => $line['side'],
                        'account_role' => $line['role'],
                        'amount_source' => $line['amount'],
                        'description' => $line['description'],
                    ]);
                }
            }
        });
    }
}

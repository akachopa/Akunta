<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\JournalLineSide;
use App\Domain\Accounting\Enums\RuleAmountSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.7: accounting_rule_lines.
 *
 * Setiap baris merujuk account role, bukan kode akun bisnis, sehingga satu rule
 * berlaku untuk seluruh tenant (plan.md §11.2–§11.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_rule_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('rule_id')->constrained('accounting_rules')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('side', 8);
            $table->string('account_role', 48);
            $table->string('amount_source', 24)->default(RuleAmountSource::Transaction->value);
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['rule_id', 'line_number']);
        });

        DB::statement(sprintf(
            'ALTER TABLE accounting_rule_lines ADD CONSTRAINT accounting_rule_lines_side_allowed CHECK (side IN (%s))',
            $this->quoted(JournalLineSide::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE accounting_rule_lines ADD CONSTRAINT accounting_rule_lines_role_allowed CHECK (account_role IN (%s))',
            $this->quoted(AccountRole::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE accounting_rule_lines ADD CONSTRAINT accounting_rule_lines_amount_source_allowed CHECK (amount_source IN (%s))',
            $this->quoted(RuleAmountSource::values())
        ));

        DB::statement('ALTER TABLE accounting_rule_lines ADD CONSTRAINT accounting_rule_lines_number_positive CHECK (line_number > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_rule_lines');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.2: accounting_periods, dengan state machine plan.md §20.1.
 *
 * Kolom closed_at dan reopened_at disimpan di sini sebagai denormalisasi ringan supaya period
 * lock dapat diperiksa tanpa join ke audit_logs; riwayat lengkapnya tetap di audit_logs
 * (plan.md §20.4 "reopen harus dicatat dalam audit log").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name', 32);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 24)->default(AccountingPeriodStatus::Open->value);
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignUuid('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->unique(['business_id', 'fiscal_year', 'period_number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};

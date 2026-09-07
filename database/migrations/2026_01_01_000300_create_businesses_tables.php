<?php

declare(strict_types=1);

use App\Domain\Business\Enums\AccountingBasis;
use App\Domain\Business\Enums\BusinessType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.1: businesses, business_users.
 *
 * Business adalah unit tenant untuk seluruh data akuntansi. Setiap tabel akuntansi
 * membawa business_id dan difilter oleh global scope tenant (plan.md §30 "tenant
 * isolation", §44.15 "jangan mengizinkan cross-tenant access").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('legal_name')->nullable();
            $table->string('business_type', 32)->default(BusinessType::General->value);
            $table->char('currency', 3)->default('IDR');
            $table->string('accounting_basis', 16)->default(AccountingBasis::Accrual->value);
            $table->string('period_length', 16)->default('monthly');
            $table->date('opening_date');
            $table->char('fiscal_year_start_month', 2)->default('01');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('business_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->boolean('is_external')->default(false);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->index(['user_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_users');
        Schema::dropIfExists('businesses');
    }
};

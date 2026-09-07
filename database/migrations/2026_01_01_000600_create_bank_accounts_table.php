<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.2: bank_accounts.
 *
 * Dibuat pada Phase 1 karena rekening bank adalah bagian dari onboarding bisnis
 * (plan.md §5.1). Kolom chart_of_account_id baru diisi setelah COA tersedia (Phase 2),
 * jadi relasinya nullable dan ditambahkan pada migration COA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('label');
            $table->string('bank_name');
            $table->string('account_number', 64);
            $table->string('account_holder')->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->string('account_kind', 24)->default('bank');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'bank_name', 'account_number']);
            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};

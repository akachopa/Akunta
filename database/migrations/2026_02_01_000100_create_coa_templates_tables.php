<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.2: coa_templates, coa_template_accounts.
 *
 * Template bersifat global (dikelola platform admin, plan.md §4.1) dan dipakai untuk
 * men-generate starter COA sesuai jenis usaha (plan.md §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coa_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('business_type', 32)->index();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coa_template_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coa_template_id')->constrained('coa_templates')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('parent_code', 32)->nullable();
            $table->string('account_type', 32);
            $table->string('normal_balance', 8);
            $table->string('account_role', 48)->nullable();
            $table->string('reporting_group', 32);
            $table->boolean('is_system')->default(true);
            $table->boolean('is_postable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['coa_template_id', 'code']);
            $table->index(['coa_template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_template_accounts');
        Schema::dropIfExists('coa_templates');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.7: accounting_rules.
 *
 * Rule bersifat global, bukan per bisnis: ia merujuk account role, dan role itu
 * di-resolve ke COA bisnis saat jurnal diusulkan (plan.md §11.1, §12.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_type_id')->constrained('economic_event_types')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('event_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_rules');
    }
};

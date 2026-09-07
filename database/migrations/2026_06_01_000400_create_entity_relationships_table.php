<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.4: entity_relationships.
 *
 * Dipakai untuk jejak merge. Entity yang diserap menunjuk entity yang bertahan, dan
 * barisnya tidak dihapus ketika merge terjadi (plan.md §31, acceptance Phase 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('from_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('to_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['from_entity_id', 'to_entity_id', 'type']);
        });

        DB::statement("ALTER TABLE entity_relationships ADD CONSTRAINT entity_relationships_type_allowed CHECK (type IN ('merged_into'))");
        DB::statement('ALTER TABLE entity_relationships ADD CONSTRAINT entity_relationships_not_self CHECK (from_entity_id <> to_entity_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_relationships');
    }
};

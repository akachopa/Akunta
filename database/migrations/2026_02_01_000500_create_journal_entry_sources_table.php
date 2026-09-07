<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.9: journal_entry_sources.
 *
 * Tabel penghubung untuk traceability plan.md §21.4 (journal → transaction → dokumen).
 * Pada Phase 2 sumber yang tercatat masih berupa referensi manual; transaction dan
 * document baru ada pada Phase 3 dan 5, sehingga source_id belum memiliki foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->uuid('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['journal_entry_id', 'source_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_sources');
    }
};

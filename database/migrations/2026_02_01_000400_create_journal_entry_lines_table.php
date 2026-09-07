<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.9 dan §24.3: journal_entry_lines.
 *
 * Kolom uang memakai NUMERIC(20,2) sesuai plan.md §24.3 dan §44.14 (tanpa floating
 * point). Constraint plan.md §24.3 ditegakkan di level database, bukan hanya di service,
 * sehingga tidak ada jalur tulis apa pun yang bisa melewatinya.
 *
 * `entity_id` dideklarasikan sebagai uuid tanpa foreign key karena tabel `entities`
 * baru dibuat pada Phase 6; foreign key-nya ditambahkan di migration phase tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->text('description')->nullable();
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->uuid('entity_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['journal_entry_id', 'line_number']);
            $table->index(['business_id', 'account_id']);
            $table->index('account_id');
            $table->index('entity_id');
        });

        // plan.md §24.3: debit >= 0, credit >= 0, NOT (debit > 0 AND credit > 0).
        DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_debit_non_negative CHECK (debit >= 0)');
        DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_credit_non_negative CHECK (credit >= 0)');
        DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_single_side CHECK (NOT (debit > 0 AND credit > 0))');

        // Baris tanpa nilai sama sekali tidak membawa informasi akuntansi apa pun.
        DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_non_empty CHECK (debit > 0 OR credit > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};

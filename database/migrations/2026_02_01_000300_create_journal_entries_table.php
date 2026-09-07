<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\JournalEntryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.9 dan §24.2: journal_entries.
 *
 * `source_type`/`source_id` sudah disediakan sejak Phase 2 sesuai plan.md §24.2 agar
 * entry yang nanti dibuat oleh rule engine (Phase 9) tidak memerlukan perubahan skema.
 * Pada Phase 2 nilainya selalu `manual`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->string('entry_number', 32);
            $table->date('entry_date');
            $table->text('description');
            $table->string('source_type', 32)->default('manual');
            $table->uuid('source_id')->nullable();
            $table->string('status', 24)->default(JournalEntryStatus::Draft->value);
            $table->char('currency', 3)->default('IDR');
            $table->decimal('total_debit', 20, 2)->default(0);
            $table->decimal('total_credit', 20, 2)->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            /*
             * plan.md §44.6: koreksi posted journal hanya lewat reversal. Kedua kolom di
             * bawah membentuk pasangan original ↔ reversal sehingga jejak koreksi tidak
             * pernah hilang.
             */
            $table->uuid('reversal_of_id')->nullable();
            $table->uuid('reversed_by_entry_id')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'entry_number']);
            $table->index(['business_id', 'status', 'entry_date']);
            $table->index(['business_id', 'accounting_period_id', 'status']);
            $table->index(['business_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });

        // Foreign key self-reference ditambahkan setelah primary key terbentuk.
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreign('reversal_of_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('reversed_by_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};

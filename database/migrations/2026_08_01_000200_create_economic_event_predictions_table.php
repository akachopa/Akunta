<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.7: economic_event_predictions.
 *
 * Prediksi per transaksi, terpisah dari `ai_predictions` generik supaya missing
 * information dan event type yang diterapkan dapat di-query tanpa membongkar JSON
 * (plan.md §14.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economic_event_predictions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('event_type_id')->constrained('economic_event_types')->restrictOnDelete();
            $table->foreignUuid('prediction_id')->nullable()->constrained('ai_predictions')->nullOnDelete();

            $table->decimal('confidence', 5, 4);
            $table->text('reason')->nullable();
            $table->jsonb('missing_information')->nullable();
            $table->string('source', 24);
            $table->string('status', 16);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['transaction_id', 'status']);
            $table->index(['business_id', 'event_type_id']);
        });

        DB::statement('ALTER TABLE economic_event_predictions ADD CONSTRAINT economic_event_predictions_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement("ALTER TABLE economic_event_predictions ADD CONSTRAINT economic_event_predictions_status_allowed CHECK (status IN ('applied', 'superseded', 'rejected'))");
        DB::statement("ALTER TABLE economic_event_predictions ADD CONSTRAINT economic_event_predictions_source_allowed CHECK (source IN ('heuristic', 'model', 'user'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('economic_event_predictions');
    }
};

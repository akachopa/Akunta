<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.6: ai_usage_logs.
 *
 * Dipisahkan dari `ai_model_runs` karena masa hidupnya berbeda. Catatan diagnostik boleh
 * dipangkas, sedangkan catatan biaya harus bertahan selama bisnis perlu menagihkan atau
 * mengaudit pemakaian AI-nya (plan.md §32.1 `AI_cost_per_document`, `AI_cost_per_business`).
 *
 * `document_id` nullable supaya task yang tidak melekat pada satu dokumen, seperti AI
 * analyst pada Phase 14, tetap dapat dicatat biayanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('model_run_id')->constrained('ai_model_runs')->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->cascadeOnDelete();

            $table->string('task', 48);
            $table->string('provider', 32);
            $table->string('model', 128);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            /*
             * plan.md §44.14 melarang float untuk uang, dan biaya AI tetap uang meski
             * satuannya dolar. Skala 6 dipakai karena harga per token berada pada orde
             * seperseribu sen: skala 2 akan membulatkan seluruh biaya per dokumen menjadi
             * nol dan membuat metrik biaya tidak berguna.
             */
            $table->decimal('cost', 20, 6)->default(0);
            $table->char('currency', 3)->default('USD');

            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'task']);
            $table->index('document_id');
        });

        DB::statement('ALTER TABLE ai_usage_logs ADD CONSTRAINT ai_usage_logs_cost_not_negative CHECK (cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.6: ai_predictions dan ai_prediction_candidates.
 *
 * Prediksi disimpan sebagai record tersendiri, bukan hanya sebagai kolom pada dokumen,
 * karena plan.md §31 melarang menimpa keputusan historis. Ketika reviewer mengoreksi jenis
 * dokumen, prediksi aslinya harus tetap dapat dibaca beserta confidence-nya; itulah bahan
 * §17.1 dan satu-satunya cara mengukur `document_type_accuracy` pada §32.2.
 *
 * Alternatif ditaruh di tabel terpisah karena plan.md §14.1 mengembalikannya sebagai daftar
 * berperingkat, dan menyimpan daftar sebagai JSON akan membuatnya tidak dapat diagregasi
 * untuk metrik akurasi.
 *
 * `subject_type` dan `subject_id` dipakai sebagai relasi polimorfik supaya prediksi
 * transaksi dan economic event pada phase berikutnya memakai tabel yang sama tanpa
 * migration baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_predictions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('model_run_id')->nullable()->constrained('ai_model_runs')->nullOnDelete();

            $table->string('subject_type');
            $table->uuid('subject_id');

            $table->string('task', 48);

            // Nilai prediksi sebagai string: jenis dokumen pada Phase 4, event code pada
            // Phase 8. Menyimpannya sebagai teks membuat tabel ini tidak perlu tahu
            // taksonomi mana yang sedang dipakai.
            $table->string('predicted_value', 64);

            $table->decimal('confidence', 5, 4);
            $table->text('reason')->nullable();

            /*
             * applied  : prediksi dipakai sebagai nilai dokumen
             * superseded: dikalahkan pernyataan user atau koreksi reviewer
             * rejected : output provider tidak lolos validasi
             */
            $table->string('status', 16);
            $table->timestamp('applied_at')->nullable();

            // Output provider apa adanya (plan.md §37 Phase 4 "raw evidence tersedia").
            $table->jsonb('raw_output')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'task', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement('ALTER TABLE ai_predictions ADD CONSTRAINT ai_predictions_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement("ALTER TABLE ai_predictions ADD CONSTRAINT ai_predictions_status_allowed CHECK (status IN ('applied', 'superseded', 'rejected'))");

        Schema::create('ai_prediction_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('prediction_id')->constrained('ai_predictions')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('candidate_value', 64);
            $table->decimal('confidence', 5, 4);
            $table->unsignedSmallInteger('rank');

            $table->timestamp('created_at')->useCurrent();

            // Satu peringkat hanya boleh ditempati satu kandidat per prediksi.
            $table->unique(['prediction_id', 'rank']);
            $table->unique(['prediction_id', 'candidate_value']);
        });

        DB::statement('ALTER TABLE ai_prediction_candidates ADD CONSTRAINT ai_prediction_candidates_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement('ALTER TABLE ai_prediction_candidates ADD CONSTRAINT ai_prediction_candidates_rank_positive CHECK (rank > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prediction_candidates');
        Schema::dropIfExists('ai_predictions');
    }
};

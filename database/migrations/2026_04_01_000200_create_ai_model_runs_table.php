<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.6: ai_model_runs.
 *
 * Satu baris per panggilan provider. Isinya murni teknis: siapa yang dipanggil, versi
 * model dan prompt apa yang dipakai, berapa lama, dan apakah berhasil (plan.md §45.9,
 * §32.1 `queue_latency`).
 *
 * Yang sengaja tidak disimpan di sini adalah isi request dan response. Keduanya memuat
 * potongan dokumen finansial, dan plan.md §30 mewajibkan redaksi data sensitif pada log.
 * Raw output tersimpan sekali saja, pada record domain yang dihasilkannya
 * (`ai_predictions.raw_output` dan `document_extractions.raw_output`), sehingga tidak ada
 * salinan kedua yang harus dijaga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            // Nama task pada plan.md §14: classify_document, extract_document, dan
            // seterusnya. Bukan enum database supaya task baru pada phase berikutnya
            // tidak memerlukan migration.
            $table->string('task', 48);

            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('prompt_version', 32);

            // Versi schema output. Untuk ekstraksi ini versi extractor; untuk klasifikasi
            // nilainya null karena schema-nya menempel pada versi prompt.
            $table->string('schema_version', 32)->nullable();

            $table->string('status', 16);
            $table->unsignedInteger('latency_ms')->nullable();

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'task', 'created_at']);
            $table->index(['business_id', 'status']);
        });

        DB::statement("ALTER TABLE ai_model_runs ADD CONSTRAINT ai_model_runs_status_allowed CHECK (status IN ('succeeded', 'rejected', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_runs');
    }
};

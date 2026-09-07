<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\ProcessingJobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.3: document_processing_jobs.
 *
 * Satu baris per percobaan satu tahap pipeline. Riwayat percobaan tidak ditimpa saat
 * retry, karena plan.md §32.1 memerlukan `average_processing_time` dan `documents_failed`,
 * dan karena user perlu melihat mengapa percobaan sebelumnya gagal sebelum mencoba lagi
 * (plan.md §37 Phase 3 "failure dapat diretry").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_processing_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('stage', 24);
            $table->string('status', 16)->default(ProcessingJobStatus::Queued->value);
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Durasi disimpan eksplisit supaya metrik processing time tidak perlu
            // menghitung ulang selisih timestamp di query laporan.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();

            // Metadata teknis hasil tahap, misalnya nama dan versi parser yang dipakai
            // (plan.md §45.9: simpan versi model/prompt).
            $table->jsonb('result')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'stage', 'attempt']);
            $table->index(['business_id', 'status']);
            $table->index(['document_id', 'stage']);
        });

        DB::statement('ALTER TABLE document_processing_jobs ADD CONSTRAINT document_processing_jobs_attempt_positive CHECK (attempt > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_processing_jobs');
    }
};

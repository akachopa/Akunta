<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.3: document_extractions.
 *
 * Satu baris per percobaan ekstraksi, bukan satu baris per dokumen. Percobaan lama tidak
 * ditimpa karena plan.md §31 melarang menghapus jejak keputusan, dan reviewer perlu
 * melihat bahwa ekstraksi sebelumnya ditolak beserta alasannya.
 *
 * Kolom `status` adalah penjaga plan.md §37 Phase 4 "invalid output tidak masuk transaction
 * pipeline": hanya ekstraksi berstatus `accepted` yang menghasilkan baris
 * `document_fields`, dan hanya field itu yang dibaca phase berikutnya. Ekstraksi yang
 * ditolak tetap tersimpan lengkap dengan raw output-nya sebagai bukti, tetapi tidak
 * meninggalkan satu pun nilai yang dapat terbaca sebagai data sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('model_run_id')->nullable()->constrained('ai_model_runs')->nullOnDelete();

            // Jenis dokumen yang menjadi dasar pemilihan schema. Disimpan ulang di sini
            // karena jenis dokumen dapat berubah setelah review, sedangkan ekstraksi ini
            // dijalankan dengan jenis yang berlaku saat itu.
            $table->string('document_type', 48);

            $table->string('extractor', 48);
            $table->string('schema_version', 32);

            $table->string('status', 16);
            $table->decimal('confidence', 5, 4)->nullable();

            // Daftar alasan penolakan. Ditampilkan ke reviewer supaya ia tahu apa yang
            // harus diperiksa, bukan hanya bahwa ekstraksinya gagal.
            $table->jsonb('validation_errors')->nullable();

            // Output provider apa adanya (plan.md §37 Phase 4 "raw + parsed evidence").
            $table->jsonb('raw_output')->nullable();

            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamps();

            $table->unique(['document_id', 'attempt']);
            $table->index(['business_id', 'status']);
            $table->index(['document_id', 'created_at']);
        });

        DB::statement("ALTER TABLE document_extractions ADD CONSTRAINT document_extractions_status_allowed CHECK (status IN ('accepted', 'rejected'))");
        DB::statement('ALTER TABLE document_extractions ADD CONSTRAINT document_extractions_confidence_range CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1))');
        DB::statement('ALTER TABLE document_extractions ADD CONSTRAINT document_extractions_attempt_positive CHECK (attempt > 0)');

        /*
         * Ekstraksi yang ditolak wajib menyebutkan alasannya. Tanpa aturan ini akan muncul
         * baris "rejected" tanpa keterangan, dan reviewer tidak punya apa pun untuk
         * dikerjakan.
         */
        DB::statement("ALTER TABLE document_extractions ADD CONSTRAINT document_extractions_rejected_needs_reason CHECK (status <> 'rejected' OR validation_errors IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};

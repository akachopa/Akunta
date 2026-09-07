<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.6 dan §17.1: ai_feedback.
 *
 * plan.md §17 memerintahkan menyimpan seluruh koreksi reviewer. Tabel ini adalah tempatnya,
 * dan keberadaannya menentukan apakah review punya nilai jangka panjang: UI review yang
 * membuang sinyal koreksi hanya membetulkan satu dokumen, sedangkan koreksi yang tercatat
 * menjadi bahan pengukuran akurasi pada plan.md §32.2.
 *
 * Yang dibangun pada Phase 4 hanyalah penyimpanannya. Learning layer plan.md §17.2 —
 * memakai koreksi untuk mempengaruhi prediksi berikutnya — belum dikerjakan, dan tidak ada
 * kode yang membaca tabel ini sebagai masukan prediksi.
 *
 * Tabel bersifat append-only. plan.md §44.7 melarang penghapusan jejak, dan koreksi adalah
 * keputusan akuntansi yang harus tetap dapat dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('prediction_id')->nullable()->constrained('ai_predictions')->nullOnDelete();

            $table->string('task', 48);

            // Field yang dikoreksi. Null berarti koreksi menyangkut dokumen secara
            // keseluruhan, misalnya jenis dokumennya.
            $table->string('field_key', 48)->nullable();

            $table->string('original_value')->nullable();
            $table->decimal('original_confidence', 5, 4)->nullable();
            $table->string('final_value')->nullable();

            // plan.md §17.1 menyimpan document_type sebagai konteks: pola kesalahan
            // classifier berbeda antar jenis dokumen.
            $table->string('document_type', 48)->nullable();

            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'task', 'created_at']);
            $table->index(['business_id', 'document_type']);
            $table->index('document_id');
        });

        DB::statement('ALTER TABLE ai_feedback ADD CONSTRAINT ai_feedback_confidence_range CHECK (original_confidence IS NULL OR (original_confidence >= 0 AND original_confidence <= 1))');

        // Koreksi yang tidak mengubah apa pun bukan koreksi. Menolaknya di database
        // menjaga metrik akurasi tidak terisi baris kosong.
        DB::statement('ALTER TABLE ai_feedback ADD CONSTRAINT ai_feedback_changes_something CHECK (original_value IS DISTINCT FROM final_value)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};

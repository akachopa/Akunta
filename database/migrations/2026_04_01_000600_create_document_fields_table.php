<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.3: document_fields.
 *
 * Tabel ini adalah sumber kebenaran nilai hasil ekstraksi, dan setiap barisnya membawa
 * buktinya sendiri: nomor halaman dan cuplikan teks tempat nilai itu terbaca
 * (plan.md §45.10, §45.12). Tanpa kedua kolom itu reviewer hanya dapat mempercayai atau
 * menolak angka tanpa dapat memeriksanya.
 *
 * `row_index` mengakomodasi dokumen yang isinya baris berulang, terutama rekening koran.
 * plan.md §8.4 memang merujuk baris di dalam dokumen sebagai evidence (`row_reference`),
 * jadi baris harus dapat dialamatkan. Field tingkat dokumen memakai `row_index` NULL.
 *
 * Nilai disimpan pada kolom bertipe, bukan satu kolom teks: uang wajib NUMERIC
 * (plan.md §44.14) dan tanggal wajib DATE agar dapat difilter dan diurutkan oleh laporan
 * pada phase berikutnya.
 *
 * Riwayat koreksi tidak disimpan di sini. Ketika reviewer mengubah nilai, barisnya
 * diperbarui dan `source` menjadi `review`; nilai aslinya tetap terbaca pada
 * `document_extractions.raw_output` dan `ai_feedback`, sesuai plan.md §31 yang melarang
 * keputusan historis ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('extraction_id')->constrained('document_extractions')->cascadeOnDelete();

            $table->string('field_key', 48);
            $table->string('kind', 16);
            $table->unsignedInteger('row_index')->nullable();

            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 2)->nullable();
            $table->date('value_date')->nullable();

            $table->decimal('confidence', 5, 4)->default(0);

            // Asal nilai: `ai` bila dari provider, `review` bila dikoreksi manusia.
            $table->string('source', 16)->default('ai');

            $table->unsignedInteger('page_number')->nullable();
            $table->text('source_text')->nullable();

            $table->boolean('is_confirmed')->default(false);
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'document_id']);
            $table->index(['document_id', 'field_key']);
        });

        /*
         * Satu field hanya boleh muncul sekali per ekstraksi. Baris tabel dibedakan oleh
         * row_index, dan karena NULL tidak dianggap sama oleh unique index PostgreSQL,
         * kedua kasusnya memerlukan partial index terpisah.
         */
        DB::statement('CREATE UNIQUE INDEX document_fields_unique_document_field ON document_fields (extraction_id, field_key) WHERE row_index IS NULL');
        DB::statement('CREATE UNIQUE INDEX document_fields_unique_row_field ON document_fields (extraction_id, row_index, field_key) WHERE row_index IS NOT NULL');

        // Nilai harus berada di kolom yang sesuai tipenya. Uang di kolom teks akan lolos
        // seluruh validasi aplikasi tetapi merusak setiap perhitungan yang membacanya.
        DB::statement(<<<'SQL'
            ALTER TABLE document_fields ADD CONSTRAINT document_fields_value_matches_kind CHECK (
                (kind = 'text' AND value_number IS NULL AND value_date IS NULL)
                OR (kind IN ('money', 'integer') AND value_text IS NULL AND value_date IS NULL)
                OR (kind = 'date' AND value_text IS NULL AND value_number IS NULL)
            )
        SQL);

        DB::statement('ALTER TABLE document_fields ADD CONSTRAINT document_fields_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement("ALTER TABLE document_fields ADD CONSTRAINT document_fields_source_allowed CHECK (source IN ('ai', 'review'))");

        /*
         * Field tanpa nilai tidak boleh membawa confidence. Confidence 0,9 pada field yang
         * kosong akan menaikkan skor dokumen tanpa satu pun data, dan itu tepat jenis
         * kesalahan yang membuat dokumen lolos otomatis padahal tidak layak
         * (plan.md §32.2).
         */
        DB::statement(<<<'SQL'
            ALTER TABLE document_fields ADD CONSTRAINT document_fields_empty_has_no_confidence CHECK (
                value_text IS NOT NULL
                OR value_number IS NOT NULL
                OR value_date IS NOT NULL
                OR confidence = 0
            )
        SQL);

        // Konfirmasi manusia harus punya pelakunya dan waktunya (plan.md §31).
        DB::statement('ALTER TABLE document_fields ADD CONSTRAINT document_fields_confirmation_has_actor CHECK (is_confirmed = false OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_fields');
    }
};

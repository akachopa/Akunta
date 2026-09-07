<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.3: document_pages.
 *
 * Menyimpan keluaran parser deterministik: satu halaman PDF, satu sheet spreadsheet,
 * satu tabel CSV, atau satu berkas gambar (plan.md §13.1 PARSER ROUTER).
 *
 * Isi halaman disimpan supaya traceability sampai berkas asli tetap ada tanpa harus
 * memparse ulang (plan.md §45.12), dan supaya Phase 4 dapat mengklasifikasi dari sumber
 * yang sama persis dengan yang dilihat user.
 *
 * `rows` memakai jsonb, bukan tabel baris tersendiri, karena Phase 3 belum menafsirkan
 * kolom mana yang tanggal atau nominal. Penafsiran itu pekerjaan Transaction
 * Normalization pada Phase 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('kind', 24);

            // Nama sheet atau nama berkas, dipakai UI untuk memberi label halaman.
            $table->string('label')->nullable();

            $table->text('text')->nullable();
            $table->unsignedInteger('char_count')->default(0);
            $table->jsonb('rows')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('needs_ocr')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'page_number']);
            $table->index(['business_id', 'document_id']);
        });

        DB::statement('ALTER TABLE document_pages ADD CONSTRAINT document_pages_page_number_positive CHECK (page_number > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
    }
};

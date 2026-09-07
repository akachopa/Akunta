<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentFileKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.3: document_files.
 *
 * Satu dokumen selalu memiliki tepat satu berkas `original` yang tidak boleh diubah
 * (plan.md §37 Phase 3 "original file tetap tersimpan"). Berkas turunan seperti
 * thumbnail boleh dibuat ulang kapan saja.
 *
 * plan.md §30 dan §44.11 melarang dokumen finansial berada di public storage, sehingga
 * `disk` selalu menunjuk disk privat dan tidak ada kolom URL publik di sini. Akses berkas
 * hanya lewat signed URL yang dibuat saat diminta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('kind', 24)->default(DocumentFileKind::Original->value);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('byte_size');
            $table->string('checksum_sha256', 64);

            // Halaman spesifik untuk berkas turunan per halaman, null untuk berkas utuh.
            $table->unsignedInteger('page_number')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['disk', 'path']);
            $table->index(['business_id', 'kind']);
        });

        DB::statement('ALTER TABLE document_files ADD CONSTRAINT document_files_byte_size_positive CHECK (byte_size > 0)');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            /*
             * Satu berkas per (dokumen, kind) untuk berkas utuh. Index parsial diperlukan
             * karena PostgreSQL menganggap NULL saling berbeda, sehingga unique index biasa
             * pada (document_id, kind, page_number) tetap mengizinkan dua berkas original.
             * Index inilah yang membuat "menimpa berkas asli" mustahil lewat jalur tulis
             * apa pun.
             */
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX document_files_unique_whole_file_idx
                ON document_files (document_id, kind)
                WHERE page_number IS NULL
            SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX document_files_unique_page_file_idx
                ON document_files (document_id, kind, page_number)
                WHERE page_number IS NOT NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};

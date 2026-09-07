<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentSourceType;
use App\Domain\Documents\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.3 dan §8.1: documents.
 *
 * Tabel ini hanya memuat kolom yang benar-benar terisi pada Phase 3, yaitu identitas
 * berkas, asalnya, dan status pipeline. Kolom hasil Document Intelligence pada plan.md
 * §8.1 (`issuer_entity_id`, `subtotal`, `tax`, `total`, `classification_confidence`,
 * `extraction_confidence`) ditambahkan pada Phase 4 dan Phase 6 bersama kode yang
 * mengisinya, supaya tidak ada kolom menggantung yang selalu null.
 *
 * `document_type` sudah ada sejak sekarang karena plan.md §5.2 mengizinkan user memberi
 * petunjuk jenis dokumen saat upload, meski tidak diwajibkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            /*
             * Referensi yang dapat dibaca manusia (plan.md §8.1 contoh "DOC-000823").
             * Dipakai pada UI dan jejak evidence agar dokumen dapat dirujuk tanpa
             * menyebut UUID.
             */
            $table->string('reference', 32);

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 32)->default(DocumentSourceType::Upload->value);
            $table->string('processing_status', 32)->default(DocumentStatus::Uploaded->value);

            $table->string('document_type', 48)->nullable();

            /*
             * Membedakan jenis dokumen yang dinyatakan user dari yang ditebak classifier.
             * plan.md §17.1 memerlukan pembedaan ini sebagai bahan feedback AI, dan
             * Phase 4 tidak boleh menimpa pernyataan user tanpa jejak.
             */
            $table->string('document_type_source', 16)->nullable();

            $table->string('original_filename');
            $table->string('file_extension', 16);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('byte_size');

            /*
             * Checksum menjaga bukti bahwa berkas yang tersimpan identik dengan yang
             * diunggah (plan.md §37 Phase 3 "original file tetap tersimpan", §45.12).
             * Deteksi duplikat antar dokumen adalah Phase 7 dan tidak dilakukan di sini,
             * sehingga kolomnya tidak unique.
             */
            $table->string('checksum_sha256', 64);

            $table->unsignedInteger('page_count')->nullable();
            $table->string('content_kind', 32)->nullable();

            // Penanda bahwa isi dokumen hanya dapat dibaca lewat OCR vision model
            // (Phase 4). Diisi parser, tidak ditebak aplikasi.
            $table->boolean('needs_ocr')->default(false);

            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);

            // plan.md §40: indexing pada date/business/status.
            $table->index(['business_id', 'processing_status']);
            $table->index(['business_id', 'uploaded_at']);
            $table->index(['business_id', 'checksum_sha256']);
            $table->index(['business_id', 'document_type']);
        });

        // Berkas berukuran nol bukan dokumen. Menolaknya di database menutup seluruh jalur tulis.
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_byte_size_positive CHECK (byte_size > 0)');

        // Jenis dokumen tanpa asalnya membuat feedback AI pada Phase 4 kehilangan konteks.
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_document_type_needs_source CHECK ((document_type IS NULL) = (document_type_source IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

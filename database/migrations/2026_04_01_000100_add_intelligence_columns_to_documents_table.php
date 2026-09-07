<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §8.1: proyeksi canonical Document setelah Document Intelligence.
 *
 * Kolom di sini adalah proyeksi, bukan sumber kebenaran. Sumbernya adalah
 * `document_fields`, yang menyimpan setiap nilai beserta halaman dan cuplikan teks
 * asalnya. Proyeksi tetap dibuat karena plan.md §8.1 menjadikannya bentuk canonical yang
 * dibaca transaction generator pada Phase 5, dan agar inbox dapat menampilkan tanggal
 * serta total dokumen tanpa menggabungkan tabel field untuk setiap baris.
 *
 * `issuer_entity_id` sengaja belum ada: entity master adalah Phase 6, dan kolom foreign
 * key yang menunjuk tabel yang belum dibuat tidak dapat dijaga constraint apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->date('document_date')->nullable()->after('document_type_source');
            $table->char('currency', 3)->nullable()->after('document_date');

            // plan.md §44.14: uang selalu NUMERIC, tidak pernah floating point.
            $table->decimal('subtotal', 20, 2)->nullable()->after('currency');
            $table->decimal('tax', 20, 2)->nullable()->after('subtotal');
            $table->decimal('total', 20, 2)->nullable()->after('tax');

            /*
             * plan.md §15.1 menyebut komponen skor terpisah, dan keduanya disimpan
             * terpisah karena artinya berbeda: dokumen dapat dikenali jenisnya dengan
             * yakin tetapi angkanya terbaca buruk, dan sebaliknya. Menggabungkannya
             * menjadi satu angka akan menyembunyikan mana yang perlu diperiksa manusia.
             *
             * Skala 4 desimal cukup untuk confidence 0.0000–1.0000.
             */
            $table->decimal('classification_confidence', 5, 4)->nullable()->after('total');
            $table->decimal('extraction_confidence', 5, 4)->nullable()->after('classification_confidence');

            $table->timestamp('classified_at')->nullable()->after('parsed_at');
            $table->timestamp('extracted_at')->nullable()->after('classified_at');

            /*
             * Jejak review manusia. plan.md §17.1 memerlukannya untuk membedakan nilai
             * hasil AI dari nilai yang sudah dikonfirmasi reviewer.
             */
            $table->timestamp('reviewed_at')->nullable()->after('extracted_at');
            $table->foreignUuid('reviewed_by')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();

            // Ringkasan alasan dokumen menunggu manusia, ditampilkan di inbox
            // (plan.md §6 "Need Information").
            $table->text('review_reason')->nullable()->after('reviewed_by');
        });

        /*
         * Confidence adalah probabilitas, jadi rentangnya ditegakkan di database. Nilai di
         * luar 0..1 akan membuat seluruh ambang plan.md §15.2 kehilangan arti, dan
         * pembatasan di level aplikasi saja tidak menutup jalur tulis lain.
         */
        foreach (['classification_confidence', 'extraction_confidence'] as $column) {
            DB::statement(sprintf(
                'ALTER TABLE documents ADD CONSTRAINT documents_%s_range CHECK (%s IS NULL OR (%s >= 0 AND %s <= 1))',
                $column,
                $column,
                $column,
                $column
            ));
        }

        // plan.md §40: indexing pada date/business/status untuk daftar bertenant.
        Schema::table('documents', function (Blueprint $table): void {
            $table->index(['business_id', 'document_date']);
        });
    }

    public function down(): void
    {
        foreach (['classification_confidence', 'extraction_confidence'] as $column) {
            DB::statement("ALTER TABLE documents DROP CONSTRAINT documents_{$column}_range");
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['business_id', 'document_date']);
            $table->dropConstrainedForeignId('reviewed_by');

            $table->dropColumn([
                'document_date',
                'currency',
                'subtotal',
                'tax',
                'total',
                'classification_confidence',
                'extraction_confidence',
                'classified_at',
                'extracted_at',
                'reviewed_at',
                'review_reason',
            ]);
        });
    }
};

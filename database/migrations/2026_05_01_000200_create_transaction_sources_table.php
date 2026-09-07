<?php

declare(strict_types=1);

use App\Domain\Transactions\Enums\TransactionSourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.5: transaction_sources.
 *
 * Asal pembentuk sebuah transaksi: dokumen mana, ekstraksi keberapa, dan bagian mana dari
 * dokumen itu. Inilah "source references" pada plan.md §37 Phase 5 dan tumpuan acceptance
 * "transaksi dapat ditelusuri ke source" serta plan.md §45.12 yang menuntut traceability
 * sampai berkas aslinya.
 *
 * Berbeda dari `transaction_evidence`: sumber adalah yang **membentuk** transaksi dan hanya
 * ada satu per transaksi, sedangkan evidence adalah seluruh bukti yang **mendukungnya** dan
 * dapat bertambah pada Phase 7 ketika dokumen terkait saling dikaitkan.
 *
 * `source_reference` adalah kunci alami unit sumber: "row:12" untuk baris mutasi ke-12,
 * "document" untuk dokumen yang seluruhnya menjadi satu transaksi. Unique index atas
 * (document_id, source_reference) inilah yang menjamin satu baris mutasi tidak pernah
 * menghasilkan dua transaksi, betapa pun sering dokumennya dinormalisasi ulang
 * (plan.md §44.9 dan §37 Phase 7 "duplicate tidak menghasilkan double transaction").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();

            // Ekstraksi yang nilainya dipakai. Menyimpannya membuat transaksi dapat
            // ditelusuri ke percobaan pembacaan tertentu, bukan hanya ke dokumennya.
            $table->foreignUuid('extraction_id')->nullable()->constrained('document_extractions')->nullOnDelete();

            $table->string('source_type', 32);
            $table->string('source_reference', 64);
            $table->unsignedInteger('row_index')->nullable();
            $table->unsignedInteger('page_number')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'source_reference']);
            $table->index(['business_id', 'document_id']);
        });

        DB::statement(sprintf(
            'ALTER TABLE transaction_sources ADD CONSTRAINT transaction_sources_type_allowed CHECK (source_type IN (%s))',
            implode(', ', array_map(
                static fn (string $value): string => "'" . $value . "'",
                TransactionSourceType::values()
            ))
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_sources');
    }
};

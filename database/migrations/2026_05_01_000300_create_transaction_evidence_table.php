<?php

declare(strict_types=1);

use App\Domain\Transactions\Enums\TransactionEvidenceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.5 dan §8.4: transaction_evidence.
 *
 * Kumpulan bukti sebuah transaksi. Bentuknya mengikuti plan.md §8.4 secara langsung:
 * `document` untuk dokumen utuh, `bank_row` beserta `row_reference` untuk satu baris di
 * dalamnya, dan `document_field` untuk angka pendukung — biaya MDR pada settlement QRIS,
 * misalnya — yang menjelaskan transaksi tanpa menjadi nominalnya.
 *
 * Tabel ini append-only. plan.md §35.3 menempatkan evidence sebagai hal pertama yang dilihat
 * user, dan plan.md §31 melarang jejak keputusan dihapus; bukti yang dapat dicabut membuat
 * transaksi yang sudah diposting kehilangan dasarnya. Ketika sebuah transaksi dibuat ulang
 * oleh normalizer, transaksinya sendiri yang diganti beserta seluruh buktinya, bukan
 * buktinya yang disunting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();

            $table->string('type', 32);

            // plan.md §8.4 `row_reference`: alamat baris di dalam dokumen, disimpan sebagai
            // teks karena bentuknya berbeda antar jenis sumber.
            $table->string('row_reference', 64)->nullable();
            $table->unsignedInteger('page_number')->nullable();

            // Field dokumen yang menjadi bukti, untuk type `document_field`.
            $table->string('field_key', 48)->nullable();
            $table->text('note')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'document_id']);
            $table->index(['transaction_id', 'type']);
        });

        DB::statement(sprintf(
            'ALTER TABLE transaction_evidence ADD CONSTRAINT transaction_evidence_type_allowed CHECK (type IN (%s))',
            implode(', ', array_map(
                static fn (string $value): string => "'" . $value . "'",
                TransactionEvidenceType::values()
            ))
        ));

        // Bukti berupa field harus menyebut field-nya, jika tidak ia tidak menunjuk apa pun.
        DB::statement("ALTER TABLE transaction_evidence ADD CONSTRAINT transaction_evidence_field_has_key CHECK (type <> 'document_field' OR field_key IS NOT NULL)");

        // Bukti berupa baris harus menyebut barisnya.
        DB::statement("ALTER TABLE transaction_evidence ADD CONSTRAINT transaction_evidence_row_has_reference CHECK (type NOT IN ('bank_row', 'spreadsheet_row') OR row_reference IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_evidence');
    }
};

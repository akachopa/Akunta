<?php

declare(strict_types=1);

use App\Domain\Transactions\Enums\TransactionDirection;
use App\Domain\Transactions\Enums\TransactionSourceType;
use App\Domain\Transactions\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.5 dan §24.1: transactions.
 *
 * Transaksi canonical adalah satu peristiwa keuangan sebagaimana terbaca dari dokumennya:
 * tanggal, nominal, arah, mata uang, dan pihak lawannya. Ia belum menjadi jurnal dan belum
 * ditafsirkan maknanya — penafsiran itu pekerjaan economic event classifier Phase 8 dan
 * accounting rule engine Phase 9.
 *
 * Tiga kolom plan.md §24.1 sengaja belum dibuat di sini:
 *
 * - `counterparty_entity_id` menunggu tabel `entities` pada Phase 6. Sampai itu ada, nama
 *   pihak lawan disimpan apa adanya pada `counterparty_name` supaya jejaknya tidak hilang
 *   dan resolusi entity nanti punya bahan untuk dicocokkan.
 * - `economic_event_id` menunggu `economic_event_predictions` pada Phase 8.
 * - `posting_date` sudah ada karena kolomnya milik transaksi itu sendiri, bukan milik phase
 *   lain: ia terisi ketika jurnalnya diposting.
 *
 * Menambahkannya sekarang tanpa foreign key hanya akan menghasilkan kolom yang tidak pernah
 * terisi dan tidak dapat ditegakkan, dan pola itu sudah dihindari sejak Phase 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            // Referensi yang dapat dibaca manusia (plan.md §8.2 contoh "TRX-239281").
            $table->string('reference', 32);

            $table->date('transaction_date');
            $table->date('posting_date')->nullable();
            $table->text('description');

            /*
             * NUMERIC(20,2), bukan float (plan.md §44.14). Nominal selalu positif; arahnya
             * dibawa `direction` supaya tidak ada dua konvensi tanda yang harus ditebak
             * setiap kali transaksi dijumlahkan.
             */
            $table->decimal('amount', 20, 2);
            $table->string('direction', 16);
            $table->char('currency', 3);

            // Nama pihak lawan sebagaimana tertulis di dokumen. Resolusinya menjadi entity
            // yang dikenal bisnis adalah Phase 6 (plan.md §9).
            $table->string('counterparty_name')->nullable();

            $table->string('source_type', 32);
            $table->string('status', 24)->default(TransactionStatus::Normalized->value);

            /*
             * Confidence transaksi diwarisi dari dokumen sumbernya, karena normalisasinya
             * sendiri deterministik: tidak ada model yang menebak nominal atau tanggal di
             * tahap ini. Mewarisinya, bukan menetapkannya 1, menjaga agar transaksi dari
             * dokumen yang pembacaannya masih kabur tidak tampak lebih pasti daripada
             * sumbernya (plan.md §15.1).
             */
            $table->decimal('overall_confidence', 5, 4)->nullable();

            // Alasan transaksi menunggu manusia, dalam bahasa yang dapat dipahami pemilik
            // usaha (plan.md §16.3).
            $table->text('review_reason')->nullable();

            $table->timestamp('normalized_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);

            // plan.md §40: indexing pada business/date/status.
            $table->index(['business_id', 'transaction_date']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'source_type']);
        });

        /*
         * Transaksi bernilai nol bukan peristiwa keuangan, dan nominal negatif berarti
         * arahnya tercatat dua kali. Keduanya ditutup di database, bukan hanya di service,
         * karena setiap phase berikutnya akan menulis ke tabel ini.
         */
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount > 0)');

        DB::statement(sprintf(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_direction_allowed CHECK (direction IN (%s))',
            $this->quoted(TransactionDirection::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_status_allowed CHECK (status IN (%s))',
            $this->quoted(TransactionStatus::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_source_type_allowed CHECK (source_type IN (%s))',
            $this->quoted(TransactionSourceType::values())
        ));

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_currency_is_iso CHECK (currency ~ '^[A-Z]{3}$')");

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_confidence_range CHECK (overall_confidence IS NULL OR (overall_confidence >= 0 AND overall_confidence <= 1))');

        // Tanggal posting tidak boleh mendahului tanggal transaksinya.
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_posting_after_transaction CHECK (posting_date IS NULL OR posting_date >= transaction_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

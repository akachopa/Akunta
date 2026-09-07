<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.5: transaction_tags.
 *
 * Penanda yang dipasang reviewer pada transaksi, misalnya "cek pajak" atau "internal".
 * Tag bukan pengganti klasifikasi peristiwa; ia hanya membantu menyaring antrean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('tag', 64);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['transaction_id', 'tag']);
            $table->index(['business_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_tags');
    }
};

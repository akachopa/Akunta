<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §24.1: counterparty_entity_id.
 *
 * Nama pihak lawan tetap tersimpan di `counterparty_name` sebagai jejak teks sumbernya.
 * Kolom ini menunjuk entity master hasil resolusi Phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignUuid('counterparty_entity_id')
                ->nullable()
                ->after('counterparty_name')
                ->constrained('entities')
                ->nullOnDelete();

            $table->index(['business_id', 'counterparty_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('counterparty_entity_id');
        });
    }
};

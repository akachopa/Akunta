<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign key `journal_entry_lines.entity_id` yang ditunda dari Phase 2 sampai
 * tabel `entities` ada (plan.md §24.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->foreign('entity_id')->references('id')->on('entities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->dropForeign(['entity_id']);
        });
    }
};

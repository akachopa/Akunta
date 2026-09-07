<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23: review_comments.
 *
 * Percakapan pada sebuah tugas review. Append-only: komentar adalah jejak keputusan
 * kolektif dan tidak boleh dihapus (plan.md §31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('review_task_id')->constrained('review_tasks')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['review_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
    }
};

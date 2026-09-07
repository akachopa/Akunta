<?php

declare(strict_types=1);

use App\Domain\Review\Enums\ReviewActionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23: review_actions.
 *
 * Append-only. Setiap keputusan manusia pada Review Center tercatat di sini
 * (plan.md §31, §44.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('review_task_id')->constrained('review_tasks')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 24);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['review_task_id', 'created_at']);
            $table->index(['business_id', 'action']);
        });

        DB::statement(sprintf(
            'ALTER TABLE review_actions ADD CONSTRAINT review_actions_action_allowed CHECK (action IN (%s))',
            $this->quoted(ReviewActionType::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('review_actions');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

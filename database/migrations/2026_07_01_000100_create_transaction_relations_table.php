<?php

declare(strict_types=1);

use App\Domain\Transactions\Enums\TransactionRelationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.5: transaction_relations.
 *
 * Menyimpan duplicate dan related secara terpisah (plan.md §18, §44.9). Confidence
 * kecocokan tersimpan per hubungan (acceptance Phase 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('from_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('to_transaction_id')->constrained('transactions')->cascadeOnDelete();

            $table->string('type', 16);
            $table->decimal('confidence', 5, 4);
            $table->jsonb('reasons')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['from_transaction_id', 'to_transaction_id', 'type']);
            $table->index(['business_id', 'type']);
        });

        DB::statement(sprintf(
            'ALTER TABLE transaction_relations ADD CONSTRAINT transaction_relations_type_allowed CHECK (type IN (%s))',
            $this->quoted(TransactionRelationType::values())
        ));

        DB::statement('ALTER TABLE transaction_relations ADD CONSTRAINT transaction_relations_not_self CHECK (from_transaction_id <> to_transaction_id)');
        DB::statement('ALTER TABLE transaction_relations ADD CONSTRAINT transaction_relations_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_relations');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

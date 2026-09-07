<?php

declare(strict_types=1);

use App\Domain\Entities\Enums\EntityAliasSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.4: entity_aliases.
 *
 * Satu nama ternormalisasi hanya boleh menunjuk satu entity aktif per bisnis, supaya
 * alias yang dikonfirmasi tidak meragukan pada transaksi berikutnya (plan.md §37 Phase 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();

            $table->string('alias');
            $table->string('normalized_alias');
            $table->string('source', 24);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['business_id', 'normalized_alias']);
            $table->index(['entity_id']);
        });

        DB::statement(sprintf(
            'ALTER TABLE entity_aliases ADD CONSTRAINT entity_aliases_source_allowed CHECK (source IN (%s))',
            $this->quoted(EntityAliasSource::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_aliases');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

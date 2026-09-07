<?php

declare(strict_types=1);

use App\Domain\Entities\Enums\EntityIdentifierKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.4: entity_identifiers.
 *
 * Identitas unik (NPWP, rekening, email, id merchant) adalah kecocokan deterministik
 * plan.md §9.3. Satu nilai per jenis per bisnis hanya boleh milik satu entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();

            $table->string('kind', 24);
            $table->string('value');
            $table->string('normalized_value');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['business_id', 'kind', 'normalized_value']);
            $table->index(['entity_id']);
        });

        DB::statement(sprintf(
            'ALTER TABLE entity_identifiers ADD CONSTRAINT entity_identifiers_kind_allowed CHECK (kind IN (%s))',
            $this->quoted(EntityIdentifierKind::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_identifiers');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

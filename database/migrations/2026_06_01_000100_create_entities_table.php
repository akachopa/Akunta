<?php

declare(strict_types=1);

use App\Domain\Entities\Enums\EntityStatus;
use App\Domain\Entities\Enums\EntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.4: entities.
 *
 * Entity master per bisnis. Nama aslinya disimpan apa adanya; pencocokan memakai
 * `normalized_name` supaya "PT Sumber Makmur" dan "SUMBER MAKMUR" bertemu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('status', 16)->default(EntityStatus::Active->value);

            $table->uuid('merged_into_id')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'normalized_name']);
            $table->index(['business_id', 'type', 'status']);
            $table->index('merged_into_id');
        });

        // Foreign key self-reference ditambahkan setelah primary key terbentuk.
        Schema::table('entities', function (Blueprint $table): void {
            $table->foreign('merged_into_id')->references('id')->on('entities')->nullOnDelete();
        });

        DB::statement(sprintf(
            'ALTER TABLE entities ADD CONSTRAINT entities_type_allowed CHECK (type IN (%s))',
            $this->quoted(EntityType::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE entities ADD CONSTRAINT entities_status_allowed CHECK (status IN (%s))',
            $this->quoted(EntityStatus::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

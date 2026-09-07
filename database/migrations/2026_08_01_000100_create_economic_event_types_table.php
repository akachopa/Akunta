<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\EconomicEventCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.7: economic_event_types.
 *
 * Taksonomi disimpan sebagai tabel supaya rule engine dan prediksi merujuk id yang
 * stabil, sementara enum PHP menjaga bahwa kode karangan tidak dapat ditulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economic_event_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('category', 32);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement(sprintf(
            'ALTER TABLE economic_event_types ADD CONSTRAINT economic_event_types_code_allowed CHECK (code IN (%s))',
            $this->quoted(EconomicEventCode::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('economic_event_types');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

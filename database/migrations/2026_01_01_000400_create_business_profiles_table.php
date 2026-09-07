<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.2: business_profiles.
 *
 * Data profil yang dikumpulkan saat onboarding (plan.md §5.1) tetapi tidak dibutuhkan
 * oleh accounting core, sehingga dipisahkan dari tabel businesses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->unique()->constrained('businesses')->cascadeOnDelete();
            $table->string('tax_id', 32)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('province', 128)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->char('country', 2)->default('ID');
            $table->string('industry_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};

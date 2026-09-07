<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §15.2: "Threshold harus configurable per business".
 *
 * Kolomnya nullable dan berarti "ikuti default aplikasi", bukan nol. Membedakan keduanya
 * penting: ambang 0 berarti setiap dokumen lolos otomatis, dan itu tidak boleh menjadi
 * akibat tak sengaja dari bisnis yang belum pernah mengatur ambangnya.
 *
 * Ambang per event type, yang juga diminta §15.2, menunggu Phase 8 bersama taksonomi
 * economic event yang menjadi kuncinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->decimal('ai_auto_ready_threshold', 5, 4)->nullable()->after('industry_note');
            $table->decimal('ai_review_threshold', 5, 4)->nullable()->after('ai_auto_ready_threshold');
        });

        foreach (['ai_auto_ready_threshold', 'ai_review_threshold'] as $column) {
            DB::statement(sprintf(
                'ALTER TABLE business_profiles ADD CONSTRAINT business_profiles_%s_range CHECK (%s IS NULL OR (%s > 0 AND %s <= 1))',
                $column,
                $column,
                $column,
                $column
            ));
        }

        /*
         * Ambang review tidak boleh melampaui ambang auto-ready. Bila terbalik, tidak ada
         * satu pun nilai confidence yang jatuh pada pita "review recommended", dan dokumen
         * akan berpindah langsung dari perlu konfirmasi manusia menjadi lolos otomatis.
         */
        DB::statement('ALTER TABLE business_profiles ADD CONSTRAINT business_profiles_thresholds_ordered CHECK (ai_review_threshold IS NULL OR ai_auto_ready_threshold IS NULL OR ai_review_threshold <= ai_auto_ready_threshold)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE business_profiles DROP CONSTRAINT business_profiles_thresholds_ordered');

        foreach (['ai_auto_ready_threshold', 'ai_review_threshold'] as $column) {
            DB::statement("ALTER TABLE business_profiles DROP CONSTRAINT business_profiles_{$column}_range");
        }

        Schema::table('business_profiles', function (Blueprint $table): void {
            $table->dropColumn(['ai_auto_ready_threshold', 'ai_review_threshold']);
        });
    }
};

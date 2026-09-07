<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23.2 dan §12.2: chart_of_accounts.
 *
 * `is_postable` membedakan akun header (mis. "1 Assets", "1100 Cash & Bank") dari akun
 * detail. Journal hanya boleh menyentuh akun postable, supaya struktur COA plan.md §12.1
 * yang bertingkat tidak menghasilkan saldo ganda di parent dan child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('coa_template_id')->nullable()->constrained('coa_templates')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('account_type', 32);
            $table->string('normal_balance', 8);
            $table->string('account_role', 48)->nullable();
            $table->string('reporting_group', 32);
            $table->text('description')->nullable();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'account_type']);
            $table->index(['business_id', 'account_role']);
            $table->index(['business_id', 'is_active', 'is_postable']);
        });

        // Foreign key self-reference ditambahkan setelah primary key terbentuk.
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });

        /*
         * plan.md §11 mensyaratkan rule engine dapat me-resolve satu akun dari sebuah
         * account_role. Untuk role yang bersifat tunggal, unique partial index mencegah
         * dua akun aktif memperebutkan role yang sama pada satu bisnis.
         *
         * CASH_OR_BANK dan FIXED_ASSET dikecualikan karena satu bisnis wajar memiliki
         * banyak rekening kas/bank dan banyak aset tetap.
         */
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX chart_of_accounts_unique_role_idx
                ON chart_of_accounts (business_id, account_role)
                WHERE account_role IS NOT NULL
                  AND is_active = true
                  AND account_role NOT IN ('CASH_OR_BANK', 'FIXED_ASSET')
            SQL);
        }

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignUuid('chart_of_account_id')
                ->nullable()
                ->after('business_id')
                ->constrained('chart_of_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS chart_of_accounts_unique_role_idx');
        }

        Schema::dropIfExists('chart_of_accounts');
    }
};

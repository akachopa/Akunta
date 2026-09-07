<?php

declare(strict_types=1);

use App\Domain\Review\Enums\ReviewSubjectType;
use App\Domain\Review\Enums\ReviewTaskKind;
use App\Domain\Review\Enums\ReviewTaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plan.md §23: review_tasks.
 *
 * Satu baris adalah satu item di antrean Review Center (plan.md §16.2). Transaksi dan
 * dokumen yang masih perlu manusia tidak digabung ke tabel lain: jejak antreannya harus
 * tetap ada setelah keputusan diambil (plan.md §31).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('subject_type', 24);
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->cascadeOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained('documents')->cascadeOnDelete();

            $table->string('kind', 32);
            $table->string('status', 24)->default(ReviewTaskStatus::Open->value);
            $table->text('reason')->nullable();

            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status', 'kind']);
            $table->index(['business_id', 'subject_type', 'status']);
            $table->index('transaction_id');
            $table->index('document_id');
        });

        DB::statement(sprintf(
            'ALTER TABLE review_tasks ADD CONSTRAINT review_tasks_subject_type_allowed CHECK (subject_type IN (%s))',
            $this->quoted(ReviewSubjectType::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE review_tasks ADD CONSTRAINT review_tasks_kind_allowed CHECK (kind IN (%s))',
            $this->quoted(ReviewTaskKind::values())
        ));

        DB::statement(sprintf(
            'ALTER TABLE review_tasks ADD CONSTRAINT review_tasks_status_allowed CHECK (status IN (%s))',
            $this->quoted(ReviewTaskStatus::values())
        ));

        DB::statement("ALTER TABLE review_tasks ADD CONSTRAINT review_tasks_subject_matches CHECK (
            (subject_type = 'transaction' AND transaction_id IS NOT NULL AND document_id IS NULL)
            OR (subject_type = 'document' AND document_id IS NOT NULL AND transaction_id IS NULL)
        )");

        // Satu tugas terbuka per transaksi. Yang selesai tetap ada sebagai jejak.
        DB::statement("CREATE UNIQUE INDEX review_tasks_open_transaction ON review_tasks (transaction_id) WHERE status IN ('open', 'awaiting_post') AND transaction_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX review_tasks_open_document ON review_tasks (document_id) WHERE status IN ('open', 'awaiting_post') AND document_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('review_tasks');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'" . $value . "'", $values));
    }
};

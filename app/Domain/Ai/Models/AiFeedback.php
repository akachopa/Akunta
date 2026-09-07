<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Enums\AiTask;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23.6 dan §17.1: ai_feedback.
 *
 * Setiap koreksi reviewer tersimpan di sini, append-only. plan.md §17 mewajibkannya, dan
 * inilah yang membedakan review yang hanya membetulkan satu dokumen dari review yang
 * menghasilkan data untuk mengukur akurasi AI (plan.md §32.2).
 *
 * Learning layer plan.md §17.2 belum dibangun: tidak ada kode yang membaca tabel ini
 * sebagai masukan prediksi berikutnya.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $document_id
 * @property string|null $prediction_id
 * @property AiTask $task
 * @property string|null $field_key
 * @property string|null $original_value
 * @property string|null $original_confidence
 * @property string|null $final_value
 * @property DocumentType|null $document_type
 * @property string|null $reviewer_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class AiFeedback extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $table = 'ai_feedback';

    public const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'document_id',
        'prediction_id',
        'task',
        'field_key',
        'original_value',
        'original_confidence',
        'final_value',
        'document_type',
        'reviewer_id',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'document_type' => DocumentType::class,
        ];
    }

    protected static function booted(): void
    {
        // plan.md §44.7: jejak koreksi tidak boleh dihapus atau diubah.
        static::updating(function (): void {
            throw new RuntimeException('Feedback AI bersifat append-only (plan.md §17).');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Feedback AI tidak dapat dihapus (plan.md §44.7).');
        });
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<AiPrediction, $this>
     */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(AiPrediction::class, 'prediction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}

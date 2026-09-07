<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Ai\Models\AiModelRun;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.3: document_extractions.
 *
 * Satu baris per percobaan ekstraksi. Baris berstatus `rejected` tetap menyimpan raw output
 * sebagai bukti tetapi tidak memiliki `document_fields` sama sekali, sehingga tidak ada
 * nilai dari ekstraksi yang gagal yang dapat terbaca sebagai data sah oleh phase berikutnya
 * (plan.md §37 Phase 4).
 *
 * @property string $id
 * @property string $document_id
 * @property string $business_id
 * @property string|null $model_run_id
 * @property DocumentType $document_type
 * @property string $extractor
 * @property string $schema_version
 * @property ExtractionStatus $status
 * @property string|null $confidence
 * @property array<int, string>|null $validation_errors
 * @property array<string, mixed>|null $raw_output
 * @property int $attempt
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int $fields_count
 */
class DocumentExtraction extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'document_id',
        'business_id',
        'model_run_id',
        'document_type',
        'extractor',
        'schema_version',
        'status',
        'confidence',
        'validation_errors',
        'raw_output',
        'attempt',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => ExtractionStatus::class,
            'validation_errors' => 'array',
            'raw_output' => 'array',
            'attempt' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<AiModelRun, $this>
     */
    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(AiModelRun::class, 'model_run_id');
    }

    /**
     * @return HasMany<DocumentField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class, 'extraction_id');
    }

    public function isAccepted(): bool
    {
        return $this->status->producesFields();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', ExtractionStatus::Accepted->value);
    }
}

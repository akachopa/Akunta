<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Documents\Enums\DocumentFileKind;
use App\Domain\Documents\Enums\DocumentSourceType;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Exceptions\InvalidDocumentTransition;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.3 dan §8.1: documents.
 *
 * @property string $id
 * @property string $business_id
 * @property string $reference
 * @property string|null $uploaded_by
 * @property DocumentSourceType $source_type
 * @property DocumentStatus $processing_status
 * @property DocumentType|null $document_type
 * @property string|null $document_type_source
 * @property string $original_filename
 * @property string $file_extension
 * @property string $mime_type
 * @property int $byte_size
 * @property string $checksum_sha256
 * @property int|null $page_count
 * @property string|null $content_kind
 * @property bool $needs_ocr
 * @property Carbon $uploaded_at
 * @property Carbon|null $parsed_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property Carbon|null $archived_at
 * @property string|null $archived_by
 * @property-read int $processing_jobs_count
 * @property-read int $pages_count
 */
class Document extends Model implements KeepsAuditSnapshot
{
    use BelongsToBusiness, HasUuids, RecordsAuditTrail;

    protected $fillable = [
        'business_id',
        'reference',
        'uploaded_by',
        'source_type',
        'processing_status',
        'document_type',
        'document_type_source',
        'original_filename',
        'file_extension',
        'mime_type',
        'byte_size',
        'checksum_sha256',
        'page_count',
        'content_kind',
        'needs_ocr',
        'uploaded_at',
        'parsed_at',
        'failed_at',
        'failure_reason',
        'archived_at',
        'archived_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => DocumentSourceType::class,
            'processing_status' => DocumentStatus::class,
            'document_type' => DocumentType::class,
            'byte_size' => 'integer',
            'page_count' => 'integer',
            'needs_ocr' => 'boolean',
            'uploaded_at' => 'datetime',
            'parsed_at' => 'datetime',
            'failed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * @return HasMany<DocumentFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class);
    }

    /**
     * Berkas asli yang diunggah user (plan.md §37 Phase 3 "original file tetap tersimpan").
     *
     * @return HasOne<DocumentFile, $this>
     */
    public function originalFile(): HasOne
    {
        return $this->hasOne(DocumentFile::class)
            ->where('kind', DocumentFileKind::Original->value)
            ->whereNull('page_number');
    }

    /**
     * @return HasMany<DocumentPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }

    /**
     * @return HasMany<DocumentProcessingJob, $this>
     */
    public function processingJobs(): HasMany
    {
        return $this->hasMany(DocumentProcessingJob::class);
    }

    /**
     * Memindahkan dokumen ke status berikutnya sesuai plan.md §25.1.
     *
     * Transisi yang tidak sah ditolak sebagai exception domain, bukan diam-diam ditulis,
     * supaya status dokumen tidak pernah melompati tahap pipeline.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(DocumentStatus $status, array $attributes = []): void
    {
        if ($this->processing_status !== $status && ! $this->processing_status->canTransitionTo($status)) {
            throw InvalidDocumentTransition::between($this->processing_status, $status);
        }

        $this->fill($attributes);
        $this->processing_status = $status;
        $this->save();
    }

    public function isProcessing(): bool
    {
        return $this->processing_status->isProcessing();
    }

    public function isArchived(): bool
    {
        return $this->processing_status->isArchived();
    }

    public function isRetryable(): bool
    {
        return $this->processing_status->isRetryable();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, DocumentStatus ...$statuses): Builder
    {
        return $query->whereIn(
            'processing_status',
            array_map(static fn (DocumentStatus $status): string => $status->value, $statuses)
        );
    }

    /**
     * Inbox default menyembunyikan arsip (plan.md §6: Archived adalah tab tersendiri).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('processing_status', '!=', DocumentStatus::Archived->value);
    }
}

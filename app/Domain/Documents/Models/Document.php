<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Ai\Models\AiPrediction;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Documents\Enums\DocumentFileKind;
use App\Domain\Documents\Enums\DocumentSourceType;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\DocumentTypeSource;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Exceptions\InvalidDocumentTransition;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 * @property DocumentTypeSource|null $document_type_source
 * @property Carbon|null $document_date
 * @property string|null $currency
 * @property string|null $subtotal
 * @property string|null $tax
 * @property string|null $total
 * @property string|null $classification_confidence
 * @property string|null $extraction_confidence
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
 * @property Carbon|null $classified_at
 * @property Carbon|null $extracted_at
 * @property Carbon|null $reviewed_at
 * @property string|null $reviewed_by
 * @property string|null $review_reason
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property Carbon|null $archived_at
 * @property string|null $archived_by
 * @property-read int $processing_jobs_count
 * @property-read int $pages_count
 * @property-read int $fields_count
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
        'document_date',
        'currency',
        'subtotal',
        'tax',
        'total',
        'classification_confidence',
        'extraction_confidence',
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
        'classified_at',
        'extracted_at',
        'reviewed_at',
        'reviewed_by',
        'review_reason',
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
            'document_type_source' => DocumentTypeSource::class,
            'byte_size' => 'integer',
            'page_count' => 'integer',
            'needs_ocr' => 'boolean',

            /*
             * Uang dan confidence tidak di-cast menjadi float. plan.md §44.14 melarang
             * float untuk uang, dan confidence yang dibulatkan float dapat melintasi
             * ambang plan.md §15.2 secara tidak terduga. Keduanya dibaca sebagai string.
             */
            'document_date' => 'date',
            'uploaded_at' => 'datetime',
            'parsed_at' => 'datetime',
            'classified_at' => 'datetime',
            'extracted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<DocumentProcessingJob, $this>
     */
    public function processingJobs(): HasMany
    {
        return $this->hasMany(DocumentProcessingJob::class);
    }

    /**
     * Seluruh percobaan ekstraksi, terbaru lebih dulu (plan.md §23.3).
     *
     * @return HasMany<DocumentExtraction, $this>
     */
    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class)->orderByDesc('attempt');
    }

    /**
     * @return HasMany<DocumentField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class);
    }

    /**
     * Ekstraksi terakhir yang diterima, satu-satunya sumber nilai yang sah.
     *
     * Diurutkan, bukan memakai `latestOfMany('attempt')`. Eloquent selalu menambahkan
     * primary key sebagai pemecah seri pada relasi one-of-many, dan `MAX(uuid)` tidak ada
     * di PostgreSQL. Pemecah seri itu pun tidak diperlukan: pasangan
     * `(document_id, attempt)` sudah unik di database, sehingga percobaan tertinggi selalu
     * tunggal.
     *
     * @return HasOne<DocumentExtraction, $this>
     */
    public function acceptedExtraction(): HasOne
    {
        return $this->hasOne(DocumentExtraction::class)
            ->where('status', ExtractionStatus::Accepted->value)
            ->orderByDesc('attempt');
    }

    /**
     * Prediksi AI atas dokumen ini, terbaru lebih dulu (plan.md §23.6).
     *
     * @return MorphMany<AiPrediction, $this>
     */
    public function predictions(): MorphMany
    {
        return $this->morphMany(AiPrediction::class, 'subject')->latest();
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
     * Jenis dokumen sudah ditetapkan manusia, sehingga classifier tidak boleh menggantinya.
     */
    public function hasHumanDocumentType(): bool
    {
        return $this->document_type !== null
            && $this->document_type_source !== null
            && $this->document_type_source->isHuman();
    }

    /**
     * Dokumen yang datanya sudah selesai dan dapat dibaca phase berikutnya.
     *
     * Batas ini adalah inti acceptance Phase 4 "invalid output tidak masuk transaction
     * pipeline", jadi ia diwujudkan sebagai satu query yang dapat diuji, bukan sebagai
     * kesepakatan tak tertulis antar phase. Dua syaratnya: status dokumen READY, dan ada
     * ekstraksi yang benar-benar diterima. Syarat kedua tidak redundan — dokumen dapat
     * mencapai READY lewat konfirmasi reviewer, dan konfirmasi itu pun harus berpijak pada
     * ekstraksi yang lolos validasi.
     *
     * Sejak Phase 5, READY berarti transaksinya sudah terbentuk: pintu masuk normalisasi
     * adalah status NORMALIZING, dan penjaga yang sama ditegakkan di sana — normalizer
     * hanya membaca `document_fields` milik ekstraksi yang diterima.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReadyForTransactionPipeline(Builder $query): Builder
    {
        return $query
            ->where('processing_status', DocumentStatus::Ready->value)
            ->whereHas('extractions', function (Builder $extractions): void {
                $extractions->where('status', ExtractionStatus::Accepted->value);
            });
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

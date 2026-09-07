<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\DocumentFileKind;
use App\Domain\Documents\Exceptions\ImmutableDocumentFile;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.3: document_files.
 *
 * @property string $id
 * @property string $document_id
 * @property string $business_id
 * @property DocumentFileKind $kind
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $byte_size
 * @property string $checksum_sha256
 * @property int|null $page_number
 * @property Carbon $created_at
 * @property Document $document
 */
class DocumentFile extends Model
{
    use BelongsToBusiness, HasUuids;

    /**
     * Baris berkas tidak pernah diubah setelah dibuat, sehingga tabelnya tidak
     * memerlukan updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'business_id',
        'kind',
        'disk',
        'path',
        'mime_type',
        'byte_size',
        'checksum_sha256',
        'page_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentFileKind::class,
            'byte_size' => 'integer',
            'page_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * plan.md §37 Phase 3 acceptance: "original file tetap tersimpan". Berkas asli
         * karena itu bersifat append-only. Reprocess membaca ulang berkas yang sama dan
         * tidak pernah menggantinya, sehingga jejak ke sumber tidak dapat terputus
         * (plan.md §45.12).
         */
        static::updating(function (self $file): void {
            throw ImmutableDocumentFile::forMutation($file);
        });

        static::deleting(function (self $file): void {
            if ($file->kind->isImmutable()) {
                throw ImmutableDocumentFile::forMutation($file);
            }
        });
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isOriginal(): bool
    {
        return $this->kind === DocumentFileKind::Original;
    }
}

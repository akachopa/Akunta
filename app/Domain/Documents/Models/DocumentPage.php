<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\DocumentPageKind;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.3: document_pages.
 *
 * @property string $id
 * @property string $document_id
 * @property string $business_id
 * @property int $page_number
 * @property DocumentPageKind $kind
 * @property string|null $label
 * @property string|null $text
 * @property int $char_count
 * @property array<int, array<int, string|null>>|null $rows
 * @property int|null $row_count
 * @property bool $needs_ocr
 * @property array<string, mixed>|null $metadata
 * @property Document $document
 */
class DocumentPage extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'document_id',
        'business_id',
        'page_number',
        'kind',
        'label',
        'text',
        'char_count',
        'rows',
        'row_count',
        'needs_ocr',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentPageKind::class,
            'page_number' => 'integer',
            'char_count' => 'integer',
            'row_count' => 'integer',
            'needs_ocr' => 'boolean',
            'rows' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isTabular(): bool
    {
        return $this->kind->isTabular();
    }

    /**
     * Potongan awal isi halaman untuk pratinjau di UI.
     *
     * Halaman tabular tidak dipotong di sini karena barisnya sudah dirender terpisah.
     */
    public function textPreview(int $limit = 400): ?string
    {
        if ($this->text === null) {
            return null;
        }

        return mb_strimwidth($this->text, 0, $limit, '…');
    }
}

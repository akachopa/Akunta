<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Enums\DocumentFieldKind;
use App\Domain\Documents\Enums\DocumentFieldSource;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.3: document_fields.
 *
 * Nilai hasil ekstraksi beserta buktinya. `page_number` dan `source_text` bukan pelengkap:
 * plan.md §45.10 dan §45.12 mewajibkan evidence sampai ke sumbernya, dan tanpa keduanya
 * reviewer hanya dapat mempercayai atau menolak angka tanpa dapat memeriksanya.
 *
 * Nilai uang dibaca sebagai string, tidak pernah float (plan.md §44.14). Cast `decimal`
 * Laravel mengembalikan string, dan itu memang yang dikehendaki.
 *
 * @property string $id
 * @property string $document_id
 * @property string $business_id
 * @property string $extraction_id
 * @property DocumentFieldKey $field_key
 * @property DocumentFieldKind $kind
 * @property int|null $row_index
 * @property string|null $value_text
 * @property string|null $value_number
 * @property Carbon|null $value_date
 * @property string $confidence
 * @property DocumentFieldSource $source
 * @property int|null $page_number
 * @property string|null $source_text
 * @property bool $is_confirmed
 * @property string|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentField extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'document_id',
        'business_id',
        'extraction_id',
        'field_key',
        'kind',
        'row_index',
        'value_text',
        'value_number',
        'value_date',
        'confidence',
        'source',
        'page_number',
        'source_text',
        'is_confirmed',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_key' => DocumentFieldKey::class,
            'kind' => DocumentFieldKind::class,
            'source' => DocumentFieldSource::class,
            'row_index' => 'integer',
            'page_number' => 'integer',
            'value_date' => 'date',
            'is_confirmed' => 'boolean',
            'confirmed_at' => 'datetime',
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
     * @return BelongsTo<DocumentExtraction, $this>
     */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(DocumentExtraction::class, 'extraction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function hasValue(): bool
    {
        return $this->value_text !== null
            || $this->value_number !== null
            || $this->value_date !== null;
    }

    /**
     * Nilai sebagai string, apa pun tipenya.
     *
     * Dipakai UI dan feedback AI, yang keduanya membandingkan nilai sebagai teks. Uang
     * tetap berupa string desimal, bukan angka terformat, agar perbandingan sebelum dan
     * sesudah koreksi tidak bergantung pada pemformatan tampilan.
     */
    public function displayValue(): ?string
    {
        return match ($this->kind) {
            DocumentFieldKind::Date => $this->value_date?->toDateString(),
            DocumentFieldKind::Money, DocumentFieldKind::Integer => $this->value_number,
            DocumentFieldKind::Text => $this->value_text,
        };
    }

    /**
     * Field tingkat dokumen, bukan bagian baris mutasi.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDocumentLevel(Builder $query): Builder
    {
        return $query->whereNull('row_index');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRows(Builder $query): Builder
    {
        return $query->whereNotNull('row_index')->orderBy('row_index');
    }
}

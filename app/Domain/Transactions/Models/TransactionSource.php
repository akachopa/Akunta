<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Models;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Enums\TransactionSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.5: transaction_sources.
 *
 * Asal pembentuk satu transaksi. `source_reference` adalah kunci alami unit sumbernya —
 * "row:12" untuk baris mutasi, "document" untuk dokumen yang seluruhnya menjadi satu
 * transaksi — dan pasangan (document_id, source_reference) unik di database, sehingga
 * normalisasi ulang tidak dapat menghasilkan transaksi kembar.
 *
 * @property string $id
 * @property string $business_id
 * @property string $transaction_id
 * @property string $document_id
 * @property string|null $extraction_id
 * @property TransactionSourceType $source_type
 * @property string $source_reference
 * @property int|null $row_index
 * @property int|null $page_number
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TransactionSource extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'transaction_id',
        'document_id',
        'extraction_id',
        'source_type',
        'source_reference',
        'row_index',
        'page_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => TransactionSourceType::class,
            'row_index' => 'integer',
            'page_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
     * Kunci alami untuk satu baris di dalam dokumen.
     */
    public static function rowReference(int $rowIndex): string
    {
        return 'row:' . $rowIndex;
    }

    /**
     * Kunci alami untuk dokumen yang seluruhnya menjadi satu transaksi.
     */
    public static function documentReference(): string
    {
        return 'document';
    }
}

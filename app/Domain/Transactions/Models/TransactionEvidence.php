<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Models;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Domain\Transactions\Enums\TransactionEvidenceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * plan.md §23.5 dan §8.4: transaction_evidence.
 *
 * Append-only. plan.md §31 melarang jejak keputusan dihapus, dan transaksi yang buktinya
 * dapat dicabut kehilangan dasar yang membuatnya dapat dipercaya (plan.md §35.3).
 *
 * @property string $id
 * @property string $business_id
 * @property string $transaction_id
 * @property string $document_id
 * @property TransactionEvidenceType $type
 * @property string|null $row_reference
 * @property int|null $page_number
 * @property DocumentFieldKey|null $field_key
 * @property string|null $note
 * @property Carbon $created_at
 */
class TransactionEvidence extends Model
{
    use BelongsToBusiness, HasUuids;

    public $timestamps = false;

    protected $table = 'transaction_evidence';

    protected $fillable = [
        'business_id',
        'transaction_id',
        'document_id',
        'type',
        'row_reference',
        'page_number',
        'field_key',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionEvidenceType::class,
            'field_key' => DocumentFieldKey::class,
            'page_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Bukti transaksi tidak dapat diubah (plan.md §31).');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('Bukti transaksi tidak dapat dihapus (plan.md §31).');
        });
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
}

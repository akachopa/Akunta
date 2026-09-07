<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.9: journal_entry_sources.
 *
 * Menyimpan referensi sumber sebuah journal entry untuk keperluan drill-down
 * plan.md §21.4.
 *
 * @property string $id
 * @property string $journal_entry_id
 * @property string $business_id
 * @property string $source_type
 * @property string|null $source_id
 * @property array<string, mixed>|null $metadata
 */
class JournalEntrySource extends Model
{
    use BelongsToBusiness, HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_MANUAL = 'manual';

    public const TYPE_REVERSAL = 'reversal';

    protected $fillable = [
        'journal_entry_id',
        'business_id',
        'source_type',
        'source_id',
        'reference',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Exceptions\ImmutablePostedJournal;
use App\Domain\Accounting\Exceptions\InvalidJournalLine;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.9 dan §24.3: journal_entry_lines.
 *
 * @property string $id
 * @property string $journal_entry_id
 * @property string $business_id
 * @property string $account_id
 * @property int $line_number
 * @property string $debit
 * @property string $credit
 * @property JournalEntry $journalEntry
 */
class JournalEntryLine extends Model
{
    use BelongsToBusiness, HasUuids;

    /**
     * Baris journal tidak pernah diubah setelah dibuat; koreksi dilakukan lewat reversal
     * (plan.md §44.6), sehingga tabel ini tidak memerlukan updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'journal_entry_id',
        'business_id',
        'account_id',
        'line_number',
        'description',
        'debit',
        'credit',
        'entity_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'line_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            /*
             * Constraint plan.md §24.3 sudah ada di database. Pemeriksaan di sini hanya
             * mengubah pelanggaran menjadi exception domain yang bisa dibaca reviewer,
             * bukan QueryException mentah.
             */
            if (Money::isNegative($line->debit) || Money::isNegative($line->credit)) {
                throw new InvalidJournalLine('Debit dan kredit tidak boleh negatif.');
            }

            if (Money::isPositive($line->debit) && Money::isPositive($line->credit)) {
                throw new InvalidJournalLine(
                    'Satu baris journal tidak boleh mengisi debit dan kredit sekaligus.'
                );
            }

            if (Money::isZero($line->debit) && Money::isZero($line->credit)) {
                throw new InvalidJournalLine('Baris journal harus memiliki nilai debit atau kredit.');
            }
        });

        static::updating(function (self $line): void {
            throw ImmutablePostedJournal::forLineMutation($line);
        });

        static::deleting(function (self $line): void {
            if ($line->journalEntry->status->isImmutable()) {
                throw ImmutablePostedJournal::forLineMutation($line);
            }
        });
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * Nilai bertanda: debit positif, kredit negatif. Dipakai untuk perhitungan saldo.
     */
    public function signedAmount(): string
    {
        return Money::subtract($this->debit, $this->credit);
    }
}

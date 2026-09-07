<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\JournalEntryStatus;
use App\Domain\Accounting\Exceptions\ImmutablePostedJournal;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.9 dan §24.2: journal_entries.
 *
 * @property string $id
 * @property string $business_id
 * @property string $accounting_period_id
 * @property string $entry_number
 * @property Carbon $entry_date
 * @property string $description
 * @property JournalEntryStatus $status
 * @property string $total_debit
 * @property string $total_credit
 * @property \Illuminate\Database\Eloquent\Collection<int, JournalEntryLine> $lines
 */
class JournalEntry extends Model implements KeepsAuditSnapshot
{
    use BelongsToBusiness, HasUuids, RecordsAuditTrail;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_REVERSAL = 'reversal';

    /**
     * Kolom yang masih boleh diubah setelah entry berstatus posted.
     *
     * plan.md §44.6 melarang perubahan posted journal; yang diizinkan hanyalah menandai
     * bahwa entry tersebut sudah dibalik oleh reversal entry, karena itu bukan perubahan
     * nilai akuntansi melainkan pencatatan jejak koreksi.
     */
    private const MUTABLE_AFTER_POSTING = [
        'status',
        'reversed_at',
        'reversed_by',
        'reversed_by_entry_id',
        'reversal_reason',
        'updated_at',
    ];

    protected $fillable = [
        'business_id',
        'accounting_period_id',
        'entry_number',
        'entry_date',
        'description',
        'source_type',
        'source_id',
        'status',
        'currency',
        'total_debit',
        'total_credit',
        'created_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reversal_of_id',
        'reversed_by_entry_id',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JournalEntryStatus::class,
            'entry_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Immutability posted journal ditegakkan di level model, bukan hanya di service,
         * supaya update lewat jalur lain (mis. relasi atau kode phase berikutnya) tetap
         * tertolak. plan.md §40 "immutable posted journal data".
         */
        static::updating(function (self $entry): void {
            $original = $entry->getOriginal('status');
            $originalStatus = $original instanceof JournalEntryStatus
                ? $original
                : JournalEntryStatus::from((string) $original);

            if (! $originalStatus->isImmutable()) {
                return;
            }

            $changed = array_keys($entry->getDirty());
            $illegal = array_diff($changed, self::MUTABLE_AFTER_POSTING);

            if ($illegal !== []) {
                throw ImmutablePostedJournal::forFields($entry, $illegal);
            }
        });

        static::deleting(function (self $entry): void {
            if ($entry->status->isImmutable()) {
                throw ImmutablePostedJournal::forDeletion($entry);
            }
        });
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    /**
     * @return HasMany<JournalEntrySource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(JournalEntrySource::class);
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Entry yang dibalik oleh entry ini.
     *
     * @return BelongsTo<self, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * Entry reversal yang membalik entry ini.
     *
     * @return BelongsTo<self, $this>
     */
    public function reversedByEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isBalanced(): bool
    {
        return Money::equals($this->total_debit, $this->total_credit);
    }

    public function isPosted(): bool
    {
        return $this->status === JournalEntryStatus::Posted;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * Entry yang statusnya masih 'posted', belum dibalik.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', JournalEntryStatus::Posted->value);
    }

    /**
     * Entry yang barisnya berada di ledger, satu-satunya sumber angka laporan
     * (plan.md §45.11). Entry 'reversed' termasuk karena reversal pasangannya yang
     * menetralkan pengaruhnya, bukan penghapusan entry aslinya (plan.md §44.6).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAffectingLedger(Builder $query): Builder
    {
        return $query->whereIn('status', JournalEntryStatus::ledgerStatuses());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to);
    }
}

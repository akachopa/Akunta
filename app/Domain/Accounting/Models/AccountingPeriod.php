<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * plan.md §23.2 dan §20: accounting_periods.
 *
 * @property string $id
 * @property string $business_id
 * @property string $name
 * @property int $fiscal_year
 * @property int $period_number
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property AccountingPeriodStatus $status
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property Carbon|null $reopened_at
 * @property string|null $reopened_by
 * @property string|null $reopen_reason
 * @property-read int $journal_entries_count
 */
class AccountingPeriod extends Model implements KeepsAuditSnapshot
{
    use BelongsToBusiness, HasUuids, RecordsAuditTrail;

    protected $fillable = [
        'business_id',
        'name',
        'fiscal_year',
        'period_number',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountingPeriodStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'fiscal_year' => 'integer',
            'period_number' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isClosed(): bool
    {
        return $this->status->isClosed();
    }

    /**
     * plan.md §20.4: journal tidak boleh diubah setelah period CLOSED.
     */
    public function acceptsJournalActivity(): bool
    {
        return $this->status->acceptsJournalActivity();
    }

    public function containsDate(Carbon $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeContainingDate(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }
}

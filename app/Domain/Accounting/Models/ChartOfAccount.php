<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\ReportingGroup;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plan.md §23.2 dan §12: chart_of_accounts.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property AccountType $account_type
 * @property NormalBalance $normal_balance
 * @property AccountRole|null $account_role
 * @property ReportingGroup $reporting_group
 * @property bool $is_postable
 * @property bool $is_system
 * @property bool $is_active
 */
class ChartOfAccount extends Model implements KeepsAuditSnapshot
{
    use BelongsToBusiness, HasUuids, RecordsAuditTrail;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'business_id',
        'parent_id',
        'coa_template_id',
        'code',
        'name',
        'account_type',
        'normal_balance',
        'account_role',
        'reporting_group',
        'description',
        'is_postable',
        'is_system',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'account_role' => AccountRole::class,
            'reporting_group' => ReportingGroup::class,
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<CoaTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CoaTemplate::class, 'coa_template_id');
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    /**
     * Hanya akun aktif dan postable yang boleh menerima journal line, supaya akun header
     * pada struktur COA plan.md §12.1 tidak ikut menampung saldo.
     */
    public function acceptsJournalLines(): bool
    {
        return $this->is_active && $this->is_postable;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true)->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithRole(Builder $query, AccountRole $role): Builder
    {
        return $query->where('account_role', $role->value);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('code');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Audit\Contracts\KeepsAuditSnapshot;
use App\Domain\Business\Enums\AccountingBasis;
use App\Domain\Business\Enums\BusinessType;
use App\Domain\Documents\Models\Document;
use App\Domain\Entities\Models\Entity;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * plan.md §23.1: businesses.
 *
 * Business adalah batas tenant untuk seluruh data akuntansi.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property BusinessType $business_type
 * @property AccountingBasis $accounting_basis
 * @property string $currency
 * @property \Illuminate\Support\Carbon $opening_date
 */
class Business extends Model implements KeepsAuditSnapshot
{
    /** @use HasFactory<\Database\Factories\BusinessFactory> */
    use HasFactory, HasUuids, RecordsAuditTrail, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'legal_name',
        'business_type',
        'currency',
        'accounting_basis',
        'period_length',
        'opening_date',
        'fiscal_year_start_month',
        'timezone',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'accounting_basis' => AccountingBasis::class,
            'opening_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasOne<BusinessProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot(['role_id', 'is_external', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<BusinessUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<AccountingPeriod, $this>
     */
    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    /**
     * @return HasMany<ChartOfAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<Entity, $this>
     */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /**
     * @return HasMany<ReviewTask, $this>
     */
    public function reviewTasks(): HasMany
    {
        return $this->hasMany(ReviewTask::class);
    }

    /*
     * Alias relasi di bawah ada karena scoped route binding Laravel mencari relasi
     * bernama bentuk plural dari nama parameter route (`{journal}` → journals()).
     * Tanpa alias ini, child model tidak dapat di-scope ke parent business-nya.
     */

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journals(): HasMany
    {
        return $this->journalEntries();
    }

    /**
     * @return HasMany<AccountingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->accountingPeriods();
    }

    /**
     * @return HasMany<BusinessUser, $this>
     */
    public function members(): HasMany
    {
        return $this->memberships();
    }

    /**
     * @return HasMany<ReviewTask, $this>
     */
    public function reviews(): HasMany
    {
        return $this->reviewTasks();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plan.md §23.7: accounting_rules.
 *
 * @property string $id
 * @property string $event_type_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 */
class AccountingRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type_id',
        'name',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EconomicEventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EconomicEventType::class, 'event_type_id');
    }

    /**
     * @return HasMany<AccountingRuleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AccountingRuleLine::class, 'rule_id')->orderBy('line_number');
    }
}

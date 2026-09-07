<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\JournalLineSide;
use App\Domain\Accounting\Enums\RuleAmountSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.7: accounting_rule_lines.
 *
 * @property string $id
 * @property string $rule_id
 * @property int $line_number
 * @property JournalLineSide $side
 * @property AccountRole $account_role
 * @property RuleAmountSource $amount_source
 * @property string|null $description
 */
class AccountingRuleLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'line_number',
        'side',
        'account_role',
        'amount_source',
        'description',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'side' => JournalLineSide::class,
            'account_role' => AccountRole::class,
            'amount_source' => RuleAmountSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AccountingRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AccountingRule::class, 'rule_id');
    }
}

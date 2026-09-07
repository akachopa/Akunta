<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\ReportingGroup;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.2: coa_template_accounts.
 *
 * Parent direferensikan lewat `parent_code`, bukan foreign key, supaya definisi template
 * dapat ditulis sebagai daftar datar dan hierarkinya dibangun saat template diterapkan.
 *
 * @property string $id
 * @property string $coa_template_id
 * @property string $code
 * @property string $name
 * @property string|null $parent_code
 * @property AccountType $account_type
 * @property NormalBalance $normal_balance
 * @property AccountRole|null $account_role
 * @property ReportingGroup $reporting_group
 * @property bool $is_postable
 * @property int $sort_order
 */
class CoaTemplateAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'coa_template_id',
        'code',
        'name',
        'parent_code',
        'account_type',
        'normal_balance',
        'account_role',
        'reporting_group',
        'is_system',
        'is_postable',
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
            'is_system' => 'boolean',
            'is_postable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CoaTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CoaTemplate::class, 'coa_template_id');
    }
}

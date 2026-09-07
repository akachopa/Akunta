<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan.md §23.2: bank_accounts.
 *
 * @property string $id
 * @property string $business_id
 * @property string|null $chart_of_account_id
 * @property string $label
 * @property bool $is_active
 */
class BankAccount extends Model
{
    use BelongsToBusiness, HasUuids;

    public const KIND_BANK = 'bank';

    public const KIND_CASH = 'cash';

    public const KIND_EWALLET = 'ewallet';

    protected $fillable = [
        'business_id',
        'chart_of_account_id',
        'label',
        'bank_name',
        'account_number',
        'account_holder',
        'currency',
        'account_kind',
        'is_primary',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Akun COA tempat mutasi rekening ini dicatat (role CASH_OR_BANK).
     *
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }
}

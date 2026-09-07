<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\ReportingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * plan.md §12.2: COA harus mendukung code, name, parent, account type, normal balance,
 * system role, reporting group, dan active/inactive.
 */
class StoreChartOfAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->route('business')?->getKey();

        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('chart_of_accounts', 'code')->where('business_id', $businessId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'account_type' => ['required', Rule::in(AccountType::values())],
            'normal_balance' => ['nullable', Rule::in(NormalBalance::values())],
            'account_role' => ['nullable', Rule::in(AccountRole::values())],
            'reporting_group' => ['nullable', Rule::in(ReportingGroup::values())],
            'description' => ['nullable', 'string'],
            'is_postable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

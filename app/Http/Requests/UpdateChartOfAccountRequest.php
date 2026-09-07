<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\ReportingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChartOfAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->route('business')?->getKey();
        $accountId = $this->route('account')?->getKey();

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                Rule::unique('chart_of_accounts', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($accountId),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'account_type' => ['sometimes', Rule::in(AccountType::values())],
            'normal_balance' => ['sometimes', Rule::in(NormalBalance::values())],
            'account_role' => ['nullable', Rule::in(AccountRole::values())],
            'reporting_group' => ['sometimes', Rule::in(ReportingGroup::values())],
            'description' => ['nullable', 'string'],
            'is_postable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

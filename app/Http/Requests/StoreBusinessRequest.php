<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Business\Enums\AccountingBasis;
use App\Domain\Business\Enums\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Field mengikuti form onboarding plan.md §5.1.
 */
class StoreBusinessRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['required', Rule::in(BusinessType::values())],
            'currency' => ['nullable', 'string', 'size:3'],
            'accounting_basis' => ['nullable', Rule::in(AccountingBasis::values())],
            'opening_date' => ['required', 'date'],
            'coa_template_code' => ['nullable', 'string', 'exists:coa_templates,code'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],

            'tax_id' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:128'],
            'province' => ['nullable', 'string', 'max:128'],
            'postal_code' => ['nullable', 'string', 'max:16'],

            'bank_accounts' => ['nullable', 'array'],
            'bank_accounts.*.label' => ['required', 'string', 'max:255'],
            'bank_accounts.*.bank_name' => ['required', 'string', 'max:255'],
            'bank_accounts.*.account_number' => ['required', 'string', 'max:64'],
            'bank_accounts.*.account_holder' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.account_kind' => ['nullable', Rule::in(['bank', 'cash', 'ewallet'])],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\ReportingGroup;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\CoaTemplate;
use App\Domain\Accounting\Models\CoaTemplateAccount;
use App\Domain\Business\Models\Business;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengelolaan chart of accounts (plan.md §12).
 */
class ChartOfAccountsService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Men-generate starter COA bisnis dari sebuah template (plan.md §5.1).
     *
     * Idempoten: akun dengan kode yang sudah ada tidak diduplikasi, sehingga menjalankan
     * ulang onboarding tidak merusak COA yang sudah dipakai.
     *
     * @return Collection<int, ChartOfAccount>
     */
    public function applyTemplate(Business $business, CoaTemplate $template): Collection
    {
        return DB::transaction(function () use ($business, $template): Collection {
            return $this->tenantContext->withBusiness($business, function () use ($business, $template): Collection {
                $existing = ChartOfAccount::query()
                    ->forBusiness($business)
                    ->get()
                    ->keyBy('code');

                /** @var array<string, ChartOfAccount> $byCode */
                $byCode = $existing->all();
                $created = new Collection;

                /*
                 * Template account diurutkan berdasarkan panjang kode lalu kode itu
                 * sendiri, sehingga parent selalu dibuat sebelum child tanpa perlu
                 * resolusi rekursif.
                 */
                $templateAccounts = $template->accounts()
                    ->get()
                    ->sortBy(static fn (CoaTemplateAccount $account): string => sprintf(
                        '%02d|%s',
                        mb_strlen($account->code),
                        $account->code
                    ));

                foreach ($templateAccounts as $templateAccount) {
                    if (isset($byCode[$templateAccount->code])) {
                        continue;
                    }

                    $parent = $templateAccount->parent_code !== null
                        ? ($byCode[$templateAccount->parent_code] ?? null)
                        : null;

                    if ($templateAccount->parent_code !== null && $parent === null) {
                        throw new RuntimeException(sprintf(
                            'Template [%s] merujuk parent_code [%s] yang tidak ada dalam template.',
                            $template->code,
                            $templateAccount->parent_code
                        ));
                    }

                    $account = ChartOfAccount::create([
                        'business_id' => $business->getKey(),
                        'parent_id' => $parent?->getKey(),
                        'coa_template_id' => $template->getKey(),
                        'code' => $templateAccount->code,
                        'name' => $templateAccount->name,
                        'account_type' => $templateAccount->account_type->value,
                        'normal_balance' => $templateAccount->normal_balance->value,
                        'account_role' => $templateAccount->account_role?->value,
                        'reporting_group' => $templateAccount->reporting_group->value,
                        'is_postable' => $templateAccount->is_postable,
                        'is_system' => true,
                        'is_active' => true,
                        'sort_order' => $templateAccount->sort_order,
                    ]);

                    $byCode[$account->code] = $account;
                    $created->push($account);
                }

                $this->auditLogger->log(
                    'chart_of_accounts.template_applied',
                    $business,
                    null,
                    [
                        'template_code' => $template->code,
                        'accounts_created' => $created->count(),
                    ],
                    business: $business
                );

                return $created;
            });
        });
    }

    /**
     * Membuat akun custom milik bisnis (plan.md §12.2 "business-specific custom account").
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createAccount(Business $business, array $attributes, ?User $actor = null): ChartOfAccount
    {
        return DB::transaction(function () use ($business, $attributes, $actor): ChartOfAccount {
            return $this->tenantContext->withBusiness($business, function () use ($business, $attributes, $actor): ChartOfAccount {
                $accountType = AccountType::from((string) $attributes['account_type']);
                $role = isset($attributes['account_role']) && $attributes['account_role'] !== null
                    ? AccountRole::from((string) $attributes['account_role'])
                    : null;

                $this->assertRoleMatchesType($role, $accountType);

                $parent = null;

                if (! empty($attributes['parent_id'])) {
                    $parent = ChartOfAccount::query()
                        ->forBusiness($business)
                        ->findOrFail($attributes['parent_id']);
                }

                $account = ChartOfAccount::create([
                    'business_id' => $business->getKey(),
                    'parent_id' => $parent?->getKey(),
                    'code' => (string) $attributes['code'],
                    'name' => (string) $attributes['name'],
                    'account_type' => $accountType->value,
                    'normal_balance' => isset($attributes['normal_balance'])
                        ? NormalBalance::from((string) $attributes['normal_balance'])->value
                        : $accountType->normalBalance()->value,
                    'account_role' => $role?->value,
                    'reporting_group' => isset($attributes['reporting_group'])
                        ? ReportingGroup::from((string) $attributes['reporting_group'])->value
                        : $this->defaultReportingGroup($accountType)->value,
                    'description' => $attributes['description'] ?? null,
                    'is_postable' => (bool) ($attributes['is_postable'] ?? true),
                    'is_system' => false,
                    'is_active' => (bool) ($attributes['is_active'] ?? true),
                    'sort_order' => (int) ($attributes['sort_order'] ?? 0),
                ]);

                $this->auditLogger->log(
                    'chart_of_accounts.created',
                    $account,
                    null,
                    $account->only(['code', 'name', 'account_type', 'account_role', 'is_postable']),
                    business: $business,
                    actor: $actor
                );

                return $account;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAccount(ChartOfAccount $account, array $attributes, ?User $actor = null): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $attributes, $actor): ChartOfAccount {
            /*
             * Akun sistem hasil template boleh diubah nama dan status aktifnya, tetapi
             * bukan tipe atau role-nya: mengubah tipe akun yang sudah punya saldo akan
             * memindahkan angka antar laporan tanpa jejak journal.
             */
            $editable = $account->is_system
                ? ['name', 'description', 'is_active', 'sort_order']
                : ['code', 'name', 'account_type', 'normal_balance', 'account_role', 'reporting_group', 'description', 'is_postable', 'is_active', 'sort_order'];

            $payload = array_intersect_key($attributes, array_flip($editable));

            if (isset($payload['account_type'])) {
                $type = AccountType::from((string) $payload['account_type']);
                $role = isset($payload['account_role']) && $payload['account_role'] !== null
                    ? AccountRole::from((string) $payload['account_role'])
                    : $account->account_role;

                $this->assertRoleMatchesType($role, $type);
            }

            if ($account->is_postable && ($payload['is_postable'] ?? true) === false && $account->journalEntryLines()->exists()) {
                throw new RuntimeException(
                    'Akun yang sudah memiliki journal entry tidak dapat diubah menjadi akun header.'
                );
            }

            $account->update($payload);

            $this->auditLogger->logChanges(
                'chart_of_accounts.updated',
                $account,
                business: $account->business,
                actor: $actor
            );

            return $account->refresh();
        });
    }

    /**
     * Menonaktifkan akun. Akun tidak pernah dihapus karena masih dirujuk journal
     * historis (plan.md §12.2 mensyaratkan flag active/inactive, bukan delete).
     */
    public function deactivateAccount(ChartOfAccount $account, ?User $actor = null): ChartOfAccount
    {
        return $this->updateAccount($account, ['is_active' => false], $actor);
    }

    /**
     * Me-resolve akun dari account role (plan.md §11: rule engine merujuk role,
     * bukan kode akun).
     */
    public function resolveByRole(Business $business, AccountRole $role): ?ChartOfAccount
    {
        return ChartOfAccount::query()
            ->forBusiness($business)
            ->postable()
            ->withRole($role)
            ->orderBy('code')
            ->first();
    }

    public function resolveByRoleOrFail(Business $business, AccountRole $role): ChartOfAccount
    {
        $account = $this->resolveByRole($business, $role);

        if ($account === null) {
            throw new RuntimeException(sprintf(
                'Bisnis [%s] tidak memiliki akun aktif dengan role [%s].',
                $business->name,
                $role->value
            ));
        }

        return $account;
    }

    private function assertRoleMatchesType(?AccountRole $role, AccountType $type): void
    {
        if ($role === null) {
            return;
        }

        if ($role->expectedAccountType() !== $type) {
            throw new RuntimeException(sprintf(
                'Account role [%s] mengharuskan account type [%s], bukan [%s].',
                $role->value,
                $role->expectedAccountType()->value,
                $type->value
            ));
        }
    }

    private function defaultReportingGroup(AccountType $type): ReportingGroup
    {
        return match ($type) {
            AccountType::Asset => ReportingGroup::CurrentAsset,
            AccountType::Liability => ReportingGroup::CurrentLiability,
            AccountType::Equity => ReportingGroup::Equity,
            AccountType::Revenue => ReportingGroup::OperatingRevenue,
            AccountType::OtherIncome => ReportingGroup::OtherRevenue,
            AccountType::CostOfSales => ReportingGroup::CostOfSales,
            AccountType::OperatingExpense => ReportingGroup::OperatingExpense,
            AccountType::OtherExpense => ReportingGroup::OtherExpense,
        };
    }
}

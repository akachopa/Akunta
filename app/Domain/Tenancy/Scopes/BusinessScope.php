<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope yang membatasi query ke business yang aktif di TenantContext.
 *
 * plan.md §44.15: cross-tenant access tidak boleh terjadi. Scope ini adalah lapisan
 * pertahanan default; policy dan middleware menjadi lapisan berikutnya.
 */
final class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->shouldScopeQueries()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('business_id'),
            $context->businessIdOrFail()
        );
    }
}

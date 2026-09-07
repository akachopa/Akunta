<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Business\Models\Business;
use App\Domain\Tenancy\Exceptions\CrossTenantWriteAttempt;
use App\Domain\Tenancy\Scopes\BusinessScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Menandai model sebagai data milik satu business (tenant).
 *
 * Selain memasang global scope, trait ini mengisi business_id otomatis saat create dan
 * menolak write yang mencoba menyimpan baris milik tenant lain (plan.md §44.15).
 *
 * @property string|null $business_id
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function (self $model): void {
            $context = app(TenantContext::class);

            if ($model->business_id === null && $context->has()) {
                $model->business_id = $context->businessId();
            }
        });

        static::saving(function (self $model): void {
            $context = app(TenantContext::class);

            if ($context->isSuppressed() || ! $context->has()) {
                return;
            }

            if ($model->business_id !== null && $model->business_id !== $context->businessId()) {
                throw new CrossTenantWriteAttempt(static::class);
            }
        });
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        return $query->where(
            $this->qualifyColumn('business_id'),
            $business instanceof Business ? $business->getKey() : $business
        );
    }
}

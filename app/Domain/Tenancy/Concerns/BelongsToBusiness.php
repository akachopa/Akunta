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

        /*
         * business_id dibaca lewat getAttribute(), bukan lewat property, karena di sini
         * model belum tersimpan sehingga kolomnya masih boleh kosong. Tipe property
         * mendeskripsikan baris yang sudah ada di database, bukan keadaan pra-simpan.
         */
        static::creating(function (self $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('business_id') === null && $context->has()) {
                $model->setAttribute('business_id', $context->businessId());
            }
        });

        static::saving(function (self $model): void {
            $context = app(TenantContext::class);

            if ($context->isSuppressed() || ! $context->has()) {
                return;
            }

            $businessId = $model->getAttribute('business_id');

            if ($businessId !== null && $businessId !== $context->businessId()) {
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

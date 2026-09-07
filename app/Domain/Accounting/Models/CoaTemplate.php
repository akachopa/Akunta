<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Business\Enums\BusinessType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * plan.md §23.2: coa_templates.
 *
 * Template bersifat global dan dikelola platform admin (plan.md §4.1), sehingga tidak
 * memakai global scope tenant.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property BusinessType $business_type
 * @property bool $is_active
 */
class CoaTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'business_type',
        'description',
        'is_system',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CoaTemplateAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(CoaTemplateAccount::class)->orderBy('sort_order')->orderBy('code');
    }

    /**
     * Template untuk jenis usaha tertentu, dengan fallback ke template umum
     * (plan.md §5.1 memiliki jenis usaha "general/other").
     */
    public static function resolveFor(BusinessType $type): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->whereIn('business_type', [$type->value, BusinessType::General->value])
            ->orderByRaw('CASE WHEN business_type = ? THEN 0 ELSE 1 END', [$type->value])
            ->first();
    }
}

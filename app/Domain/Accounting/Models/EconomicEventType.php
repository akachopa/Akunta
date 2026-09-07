<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\EconomicEventCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * plan.md §23.7: economic_event_types.
 *
 * @property string $id
 * @property string $code
 * @property string $category
 * @property string $name
 * @property bool $is_active
 */
class EconomicEventType extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'category',
        'name',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function eventCode(): EconomicEventCode
    {
        return EconomicEventCode::from($this->code);
    }

    /**
     * @return HasOne<AccountingRule, $this>
     */
    public function rule(): HasOne
    {
        return $this->hasOne(AccountingRule::class, 'event_type_id');
    }

    /**
     * @return HasMany<EconomicEventPrediction, $this>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(EconomicEventPrediction::class, 'event_type_id');
    }

    public static function byCode(EconomicEventCode $code): self
    {
        /** @var self $type */
        $type = self::query()->where('code', $code->value)->firstOrFail();

        return $type;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Domain\Tenancy\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * plan.md §23.2: business_profiles.
 *
 * @property string $id
 * @property string $business_id
 */
class BusinessProfile extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'business_id',
        'tax_id',
        'phone',
        'email',
        'website',
        'address',
        'city',
        'province',
        'postal_code',
        'country',
        'industry_note',
    ];
}

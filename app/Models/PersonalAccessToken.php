<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token Sanctum dengan primary key UUID, mengikuti konvensi identifier plan.md §24.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;
}

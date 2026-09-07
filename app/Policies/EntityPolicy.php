<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Entities\Models\Entity;
use App\Models\User;

class EntityPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::EntityView);
    }

    public function view(User $user, Entity $entity): bool
    {
        return $user->hasBusinessPermission($entity->business_id, PermissionSlug::EntityView);
    }

    public function manage(User $user, Entity $entity): bool
    {
        return $user->hasBusinessPermission($entity->business_id, PermissionSlug::EntityManage);
    }

    public function manageAny(User $user, Business $business): bool
    {
        return $user->hasBusinessPermission($business, PermissionSlug::EntityManage);
    }
}

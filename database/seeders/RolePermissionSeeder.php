<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Enums\RoleSlug;
use App\Domain\Business\Models\Permission;
use App\Domain\Business\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder role dan permission (plan.md §4).
 *
 * Idempoten agar dapat dijalankan ulang setelah penambahan permission di phase
 * berikutnya tanpa menghapus data.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            /** @var array<string, Permission> $permissions */
            $permissions = [];

            foreach (PermissionSlug::cases() as $slug) {
                $permissions[$slug->value] = Permission::query()->updateOrCreate(
                    ['slug' => $slug->value],
                    ['group' => $slug->group(), 'description' => $slug->description()]
                );
            }

            foreach (RoleSlug::cases() as $roleSlug) {
                $role = Role::query()->updateOrCreate(
                    ['slug' => $roleSlug->value],
                    [
                        'name' => $roleSlug->label(),
                        'scope' => $roleSlug->isBusinessScoped() ? 'business' : 'platform',
                    ]
                );

                $role->permissions()->sync(array_map(
                    static fn (PermissionSlug $slug): string => $permissions[$slug->value]->getKey(),
                    $roleSlug->permissions()
                ));
            }
        });
    }
}

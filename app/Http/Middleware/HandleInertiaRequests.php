<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Business\Enums\PermissionSlug;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\Permission;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $business = $this->tenantContext->business();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_platform_admin' => $user->is_platform_admin,
                ] : null,
            ],
            'currentBusiness' => $business instanceof Business ? [
                'id' => $business->getKey(),
                'name' => $business->name,
                'currency' => $business->currency,
                'business_type' => $business->business_type->value,
            ] : null,
            'businesses' => fn (): array => $user instanceof User
                ? $user->businesses()->orderBy('name')->get()->map(fn (Business $item): array => [
                    'id' => $item->getKey(),
                    'name' => $item->name,
                ])->all()
                : [],
            'permissions' => fn (): array => $this->permissionsFor($user, $business),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function permissionsFor(mixed $user, ?Business $business): array
    {
        if (! $user instanceof User || ! $business instanceof Business) {
            return [];
        }

        if ($user->is_platform_admin) {
            return PermissionSlug::values();
        }

        $role = $user->roleIn($business);

        if ($role === null) {
            return [];
        }

        return $role->permissions
            ->map(static fn (Permission $permission): string => $permission->slug->value)
            ->all();
    }
}

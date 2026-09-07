<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Business\Models\Business;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Menetapkan business aktif dari route dan memverifikasi keanggotaan user.
 *
 * plan.md §44.15: cross-tenant access tidak boleh terjadi. Middleware ini memakai 404,
 * bukan 403, ketika user bukan member: keberadaan sebuah business milik tenant lain
 * tidak boleh dapat disimpulkan dari respons.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $business = $this->resolveBusiness($request);

        if (! $business instanceof Business) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw new NotFoundHttpException;
        }

        if (! $user->is_platform_admin && ! $user->belongsToBusiness($business)) {
            throw new NotFoundHttpException;
        }

        $this->tenantContext->set($business);

        return $next($request);
    }

    /**
     * Business dapat berasal dari parameter `{business}` atau, pada route seperti
     * `/journals/{journal}` (plan.md §29.6), dari model lain yang membawa business_id.
     */
    private function resolveBusiness(Request $request): ?Business
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Business) {
                return $parameter;
            }
        }

        foreach ($route->parameters() as $parameter) {
            if (! $parameter instanceof Model) {
                continue;
            }

            $businessId = $parameter->getAttribute('business_id');

            if (is_string($businessId)) {
                return Business::query()->find($businessId);
            }
        }

        return null;
    }
}

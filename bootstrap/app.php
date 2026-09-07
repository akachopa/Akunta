<?php

declare(strict_types=1);

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Tenancy\Exceptions\CrossTenantWriteAttempt;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            ResolveTenant::class,
        ]);

        $middleware->api(append: [
            ResolveTenant::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Pelanggaran aturan akuntansi adalah kesalahan input yang dapat diperbaiki
         * pengguna, bukan kegagalan server. plan.md §11.7 mewajibkan posting yang tidak
         * balance diblokir; blokirnya harus terbaca sebagai validation error.
         */
        $exceptions->render(function (AccountingException $exception, Request $request): ?Response {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['accounting' => [$exception->getMessage()]],
                ], 422);
            }

            return back()->withInput()->withErrors(['accounting' => $exception->getMessage()]);
        });

        $exceptions->render(function (CrossTenantWriteAttempt $exception, Request $request): ?Response {
            report($exception);

            return $request->expectsJson()
                ? response()->json(['message' => 'Operasi ditolak.'], 403)
                : response('Operasi ditolak.', 403);
        });
    })->create();

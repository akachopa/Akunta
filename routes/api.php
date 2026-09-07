<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountingPeriodController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * Prefix /api/v1 dikonfigurasi di bootstrap/app.php (plan.md §29).
 *
 * Endpoint yang tersedia dibatasi pada Phase 1–2: auth, businesses, accounts, journals,
 * periods, dan trial balance. Endpoint documents, transactions, review, reconciliation,
 * dan AI analyst pada plan.md §29.3–§29.5, §29.8, dan §29.10 belum dibuat karena berada
 * di phase berikutnya.
 */

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.me');

    Route::get('businesses', [BusinessController::class, 'index'])->name('api.businesses.index');
    Route::post('businesses', [BusinessController::class, 'store'])->name('api.businesses.store');

    Route::scopeBindings()->group(function (): void {
        Route::get('businesses/{business}', [BusinessController::class, 'show'])->name('api.businesses.show');
        Route::patch('businesses/{business}', [BusinessController::class, 'update'])->name('api.businesses.update');

        Route::get('businesses/{business}/accounts', [AccountController::class, 'index'])->name('api.accounts.index');
        Route::post('businesses/{business}/accounts', [AccountController::class, 'store'])->name('api.accounts.store');

        Route::get('businesses/{business}/journals', [JournalController::class, 'index'])->name('api.journals.index');
        Route::post('businesses/{business}/journals', [JournalController::class, 'store'])->name('api.journals.store');

        Route::get('businesses/{business}/periods', [AccountingPeriodController::class, 'index'])->name('api.periods.index');

        Route::get('businesses/{business}/reports/trial-balance', [ReportController::class, 'trialBalance'])
            ->name('api.reports.trial-balance');
    });

    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('api.journals.show');
    Route::post('journals/{journal}/approve', [JournalController::class, 'approve'])->name('api.journals.approve');
    Route::post('journals/{journal}/post', [JournalController::class, 'post'])->name('api.journals.post');
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('api.journals.reverse');

    Route::post('periods/{period}/close', [AccountingPeriodController::class, 'close'])->name('api.periods.close');
    Route::post('periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->name('api.periods.reopen');
});

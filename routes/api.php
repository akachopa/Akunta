<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountingPeriodController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EntityController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

/*
 * Prefix /api/v1 dikonfigurasi di bootstrap/app.php (plan.md §29).
 *
 * Endpoint yang tersedia: auth, businesses, accounts, journals, periods, trial balance,
 * documents, review dokumen, transaksi, dan entity. Tindakan approve/reject transaksi
 * (plan.md §29.4) dan reconciliation §29.5 menunggu Phase 10–11.
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

        Route::get('businesses/{business}/documents', [DocumentController::class, 'index'])->name('api.documents.index');
        Route::post('businesses/{business}/documents', [DocumentController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('api.documents.store');

        Route::get('businesses/{business}/transactions', [TransactionController::class, 'index'])
            ->name('api.transactions.index');

        Route::get('businesses/{business}/entities', [EntityController::class, 'index'])
            ->name('api.entities.index');

        Route::get('businesses/{business}/accounts', [AccountController::class, 'index'])->name('api.accounts.index');
        Route::post('businesses/{business}/accounts', [AccountController::class, 'store'])->name('api.accounts.store');

        Route::get('businesses/{business}/journals', [JournalController::class, 'index'])->name('api.journals.index');
        Route::post('businesses/{business}/journals', [JournalController::class, 'store'])->name('api.journals.store');

        Route::get('businesses/{business}/periods', [AccountingPeriodController::class, 'index'])->name('api.periods.index');

        Route::get('businesses/{business}/reports/trial-balance', [ReportController::class, 'trialBalance'])
            ->name('api.reports.trial-balance');
    });

    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('api.documents.show');
    Route::post('documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->name('api.documents.reprocess');
    Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->name('api.documents.archive');

    // plan.md §29.8 sebatas yang menyangkut dokumen; review transaksi menyusul pada Phase 10.
    Route::post('documents/{document}/review/type', [DocumentController::class, 'confirmType'])->name('api.documents.review.type');
    Route::post('documents/{document}/review/fields', [DocumentController::class, 'confirmFields'])->name('api.documents.review.fields');
    Route::post('documents/{document}/review/approve', [DocumentController::class, 'approve'])->name('api.documents.review.approve');

    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('api.transactions.show');
    Route::get('entities/{entity}', [EntityController::class, 'show'])->name('api.entities.show');

    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('api.journals.show');
    Route::post('journals/{journal}/approve', [JournalController::class, 'approve'])->name('api.journals.approve');
    Route::post('journals/{journal}/post', [JournalController::class, 'post'])->name('api.journals.post');
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('api.journals.reverse');

    Route::post('periods/{period}/close', [AccountingPeriodController::class, 'close'])->name('api.periods.close');
    Route::post('periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->name('api.periods.reopen');
});

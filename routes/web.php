<?php

declare(strict_types=1);

use App\Http\Controllers\AccountingPeriodController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\BusinessMemberController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\DocumentReviewController;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TrialBalanceController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('businesses', [BusinessController::class, 'index'])->name('businesses.index');
    Route::get('businesses/create', [BusinessController::class, 'create'])->name('businesses.create');
    Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');

    /*
     * scopeBindings() memaksa child model di-resolve melalui relasi business-nya,
     * sehingga id milik tenant lain menghasilkan 404, bukan 403 (plan.md §44.15).
     */
    Route::prefix('businesses/{business}')
        ->name('businesses.')
        ->scopeBindings()
        ->group(function (): void {
            Route::get('/', [BusinessController::class, 'show'])->name('show');
            Route::patch('/', [BusinessController::class, 'update'])->name('update');

            Route::get('members', [BusinessMemberController::class, 'index'])->name('members.index');
            Route::post('members', [BusinessMemberController::class, 'store'])->name('members.store');
            Route::patch('members/{member}', [BusinessMemberController::class, 'update'])->name('members.update');
            Route::delete('members/{member}', [BusinessMemberController::class, 'destroy'])->name('members.destroy');

            /*
             * plan.md §6 dan §35.2: inbox adalah pintu masuk utama alur kerja, jadi
             * route-nya berada langsung di bawah workspace bisnis.
             */
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [DocumentController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('documents.store');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
            Route::get('documents/{document}/download', DocumentDownloadController::class)->name('documents.download');
            Route::post('documents/{document}/reprocess', [DocumentController::class, 'reprocess'])->name('documents.reprocess');
            Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');

            /*
             * plan.md §16: review adalah antrean kerja tersendiri, jadi tindakannya
             * dipisahkan per maksud alih-alih menjadi satu endpoint "simpan review".
             */
            Route::post('documents/{document}/review/type', [DocumentReviewController::class, 'type'])->name('documents.review.type');
            Route::post('documents/{document}/review/fields', [DocumentReviewController::class, 'fields'])->name('documents.review.fields');
            Route::post('documents/{document}/review/approve', [DocumentReviewController::class, 'approve'])->name('documents.review.approve');

            /*
             * plan.md §37 Phase 5: transaksi canonical hasil normalisasi dokumen. Halaman
             * ini menampilkan apa yang terbaca, bukan penafsiran akuntansinya.
             */
            Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
            Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
            Route::post('transactions/{transaction}/classify', [TransactionController::class, 'classify'])->name('transactions.classify');

            Route::get('entities', [EntityController::class, 'index'])->name('entities.index');
            Route::get('entities/{entity}', [EntityController::class, 'show'])->name('entities.show');
            Route::post('entities/{entity}/confirm', [EntityController::class, 'confirm'])->name('entities.confirm');
            Route::post('entities/{entity}/merge', [EntityController::class, 'mergeInto'])->name('entities.merge');

            Route::get('accounts', [ChartOfAccountController::class, 'index'])->name('accounts.index');
            Route::post('accounts', [ChartOfAccountController::class, 'store'])->name('accounts.store');
            Route::patch('accounts/{account}', [ChartOfAccountController::class, 'update'])->name('accounts.update');
            Route::delete('accounts/{account}', [ChartOfAccountController::class, 'destroy'])->name('accounts.destroy');

            Route::get('journals', [JournalEntryController::class, 'index'])->name('journals.index');
            Route::post('journals', [JournalEntryController::class, 'store'])->name('journals.store');
            Route::get('journals/{journal}', [JournalEntryController::class, 'show'])->name('journals.show');
            Route::post('journals/{journal}/approve', [JournalEntryController::class, 'approve'])->name('journals.approve');
            Route::post('journals/{journal}/post', [JournalEntryController::class, 'post'])->name('journals.post');
            Route::post('journals/{journal}/reverse', [JournalEntryController::class, 'reverse'])->name('journals.reverse');

            Route::get('periods', [AccountingPeriodController::class, 'index'])->name('periods.index');
            Route::post('periods/{period}/close', [AccountingPeriodController::class, 'close'])->name('periods.close');
            Route::post('periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->name('periods.reopen');

            Route::get('reports/trial-balance', TrialBalanceController::class)->name('reports.trial-balance');
        });
});

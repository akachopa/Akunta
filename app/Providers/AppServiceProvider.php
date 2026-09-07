<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Domain\Entities\Models\Entity;
use App\Domain\Review\Models\ReviewTask;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Transactions\Models\Transaction;
use App\Models\PersonalAccessToken;
use App\Policies\AccountingPeriodPolicy;
use App\Policies\BusinessPolicy;
use App\Policies\ChartOfAccountPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EntityPolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\ReviewTaskPolicy;
use App\Policies\TransactionPolicy;
use App\Services\Ai\AiWorkerClient;
use App\Services\Ai\ConfidenceEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Tenant context bersifat per-request/per-job. `scoped` memastikan queue worker
         * yang berumur panjang tidak membawa tenant dari job sebelumnya (plan.md §44.15).
         */
        $this->app->scoped(TenantContext::class, static fn (): TenantContext => new TenantContext);

        $this->app->singleton(AiWorkerClient::class, static function (): AiWorkerClient {
            /** @var array{url: string, token: string|null, timeout: int} $config */
            $config = config('akunta.ai_worker');

            return new AiWorkerClient($config['url'], $config['token'], $config['timeout']);
        });

        $this->app->singleton(ConfidenceEngine::class, static function (): ConfidenceEngine {
            /** @var array{auto_ready: string, review_recommended: string} $config */
            $config = config('akunta.confidence');

            return new ConfidenceEngine($config['auto_ready'], $config['review_recommended']);
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
         * Policy didaftarkan eksplisit karena model berada di app/Domain/**\/Models
         * (plan.md §27), di luar konvensi auto-discovery Laravel.
         */
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(ChartOfAccount::class, ChartOfAccountPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(AccountingPeriod::class, AccountingPeriodPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(Entity::class, EntityPolicy::class);
        Gate::policy(ReviewTask::class, ReviewTaskPolicy::class);

        /*
         * Atribut yang dikirim ke model tetapi tidak fillable akan melempar exception di
         * luar production. Data akuntansi yang hilang tanpa suara lebih berbahaya
         * daripada error yang terlihat.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}

<?php

declare(strict_types=1);

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
 * plan.md §37 Phase 0 acceptance: "queue berjalan".
 */

it('mengirim job ke queue', function (): void {
    Queue::fake();

    QueueHeartbeatJob::dispatch('token-uji');

    Queue::assertPushed(QueueHeartbeatJob::class);
});

it('mengeksekusi job heartbeat sampai selesai', function (): void {
    Cache::forget(QueueHeartbeatJob::CACHE_KEY);

    QueueHeartbeatJob::dispatch('token-uji');

    expect(Cache::get(QueueHeartbeatJob::CACHE_KEY))
        ->toMatchArray(['token' => 'token-uji']);
});

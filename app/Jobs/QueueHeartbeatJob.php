<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job paling sederhana untuk membuktikan queue berjalan.
 *
 * plan.md §37 Phase 0 menjadikan "queue berjalan" sebagai acceptance criteria. Job ini
 * dipakai oleh perintah `akunta:health` dan oleh test smoke queue, bukan oleh alur
 * bisnis, sehingga tidak menambah fitur di luar Phase 0.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public const CACHE_KEY = 'akunta:queue:last-heartbeat';

    public function __construct(private readonly string $token)
    {
    }

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, [
            'token' => $this->token,
            'handled_at' => now()->toIso8601String(),
        ], now()->addHour());

        Log::info('Queue heartbeat handled.', ['token' => $this->token]);
    }
}

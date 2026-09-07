<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeatJob;
use App\Services\Ai\AiWorkerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verifikasi dependensi Phase 0: database, cache/queue backend, object storage, dan
 * AI worker (plan.md §37 Phase 0 acceptance).
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'akunta:health {--dispatch-queue-probe : Kirim job heartbeat untuk memastikan worker mengeksekusi job}';

    protected $description = 'Memeriksa kesiapan database, Redis, object storage, dan AI worker';

    public function handle(AiWorkerClient $aiWorker): int
    {
        $checks = [
            'database' => $this->check(function (): string {
                DB::connection()->getPdo();

                return DB::connection()->getDriverName();
            }),
            'cache' => $this->check(function (): string {
                $key = 'akunta:health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $value = Cache::get($key);
                Cache::forget($key);

                if ($value !== 'ok') {
                    throw new \RuntimeException('Cache store tidak mengembalikan nilai yang ditulis.');
                }

                return (string) config('cache.default');
            }),
            'object_storage' => $this->check(function (): string {
                $disk = (string) config('akunta.documents.disk');
                Storage::disk($disk)->directories();

                return $disk;
            }),
            'ai_worker' => $this->check(function () use ($aiWorker): string {
                $health = $aiWorker->health();

                if (! $health['reachable']) {
                    throw new \RuntimeException((string) $health['error']);
                }

                return (string) $health['status'];
            }),
        ];

        if ($this->option('dispatch-queue-probe')) {
            $checks['queue'] = $this->check(function (): string {
                $token = Str::uuid()->toString();
                QueueHeartbeatJob::dispatch($token);

                return 'job dikirim ke queue '.(string) config('queue.default');
            });
        }

        $rows = [];
        $failed = false;

        foreach ($checks as $name => $result) {
            $rows[] = [$name, $result['ok'] ? 'OK' : 'FAILED', $result['detail']];
            $failed = $failed || ! $result['ok'];
        }

        $this->table(['Komponen', 'Status', 'Detail'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }
}

<?php

declare(strict_types=1);

use App\Services\Ai\AiWorkerClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * plan.md §37 Phase 0 acceptance: "AI worker reachable".
 *
 * Worker sungguhan tidak dijalankan di dalam test PHP; kontrak HTTP-nya yang diuji di
 * sini, sementara worker itu sendiri punya test pytest sendiri (ai-worker/app/tests).
 */

it('melaporkan worker reachable ketika health endpoint sehat', function (): void {
    Http::fake([
        'ai-worker.test/health' => Http::response([
            'status' => 'ok',
            'service' => 'akunta-ai-worker',
            'provider' => 'null',
        ]),
    ]);

    $health = app(AiWorkerClient::class)->health();

    expect($health['reachable'])->toBeTrue();
    expect($health['status'])->toBe('ok');
    expect($health['error'])->toBeNull();
});

it('mengirim bearer token internal ke worker', function (): void {
    Http::fake(['ai-worker.test/*' => Http::response(['status' => 'ok'])]);

    app(AiWorkerClient::class)->health();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer testing-token'));
});

it('melaporkan tidak reachable ketika koneksi gagal', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('connection refused'));

    $health = app(AiWorkerClient::class)->health();

    expect($health['reachable'])->toBeFalse();
    expect($health['status'])->toBe('unreachable');
    expect($health['error'])->toContain('connection refused');
});

it('melaporkan tidak reachable ketika worker merespons error', function (): void {
    Http::fake(['ai-worker.test/health' => Http::response([], 503)]);

    $health = app(AiWorkerClient::class)->health();

    expect($health['reachable'])->toBeFalse();
    expect($health['status'])->toBe('error');
    expect($health['error'])->toContain('503');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * plan.md §37 Phase 0: perintah ini adalah alat verifikasi bahwa database, cache,
 * object storage, dan AI worker benar-benar tersedia di sebuah environment.
 */

it('melaporkan sukses ketika seluruh dependensi tersedia', function (): void {
    Storage::fake('documents');
    Http::fake(['ai-worker.test/health' => Http::response(['status' => 'ok'])]);

    $this->artisan('akunta:health')
        ->assertSuccessful();
});

it('gagal ketika AI worker tidak reachable', function (): void {
    Storage::fake('documents');
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('connection refused'));

    $this->artisan('akunta:health')
        ->assertFailed();
});

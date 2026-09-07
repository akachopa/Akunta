<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP menuju Python AI Worker (plan.md §26.2).
 *
 * Phase 0 hanya membutuhkan worker yang reachable; endpoint parsing, klasifikasi, dan
 * ekstraksi ditambahkan pada Phase 3–4. Klien ini sengaja tidak mengetahui provider AI
 * apa pun, karena pemilihan provider berada di dalam worker (plan.md §13.3, §44.12).
 */
class AiWorkerClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout,
    ) {}

    /**
     * @return array{reachable: bool, status: string, detail: array<string, mixed>|null, error: string|null}
     */
    public function health(): array
    {
        try {
            $response = $this->request()->get('/health');
        } catch (ConnectionException $exception) {
            return [
                'reachable' => false,
                'status' => 'unreachable',
                'detail' => null,
                'error' => $exception->getMessage(),
            ];
        }

        if ($response->failed()) {
            return [
                'reachable' => false,
                'status' => 'error',
                'detail' => null,
                'error' => 'AI worker merespons dengan status HTTP ' . $response->status() . '.',
            ];
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return [
            'reachable' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'detail' => $payload,
            'error' => null,
        ];
    }

    public function isReachable(): bool
    {
        return $this->health()['reachable'];
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($this->timeout)
            ->acceptJson();

        /*
         * plan.md §30: secret lewat environment, dan komunikasi internal tetap
         * memerlukan autentikasi meskipun worker tidak diekspos ke publik.
         */
        if ($this->token !== null && $this->token !== '') {
            $request = $request->withToken($this->token);
        }

        return $request;
    }
}

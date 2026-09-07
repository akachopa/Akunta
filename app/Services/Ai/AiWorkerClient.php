<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Documents\Exceptions\DocumentParsingFailed;
use App\Domain\Documents\Exceptions\UnsupportedDocumentFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP menuju Python AI Worker (plan.md §26.2).
 *
 * Endpoint yang dipakai saat ini: health check dan parsing deterministik berkas dokumen.
 * Klasifikasi dan ekstraksi terstruktur menyusul pada Phase 4. Klien ini sengaja tidak
 * mengetahui provider AI apa pun, karena pemilihan provider berada di dalam worker
 * (plan.md §13.3, §44.12).
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

    /**
     * Memparse berkas dokumen lewat parser deterministik worker (plan.md §13.1).
     *
     * Berkas dikirim sebagai multipart, bukan sebagai path storage, supaya worker tidak
     * memerlukan kredensial object storage sama sekali (plan.md §30).
     */
    public function parseDocument(string $contents, string $filename, string $mimeType): DocumentParseResult
    {
        try {
            $response = $this->request()
                ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
                ->post('/v1/parse', [
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                ]);
        } catch (ConnectionException $exception) {
            throw DocumentParsingFailed::workerUnreachable($exception->getMessage());
        }

        /*
         * Worker memisahkan 415 (tidak ada parser) dari 422 (berkas rusak). Pembedaan itu
         * menentukan status akhir dokumen: UNSUPPORTED tidak akan berubah meski diretry
         * dengan berkas yang sama, sedangkan FAILED bisa berhasil setelah retry.
         */
        if ($response->status() === 415) {
            throw new UnsupportedDocumentFile($this->extractDetail($response->json()));
        }

        if ($response->failed()) {
            throw DocumentParsingFailed::workerRejected(
                $response->status(),
                $this->extractDetail($response->json())
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return DocumentParseResult::fromArray($payload);
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

    private function extractDetail(mixed $payload): string
    {
        if (is_array($payload) && is_string($payload['detail'] ?? null)) {
            return $payload['detail'];
        }

        return 'Worker tidak menyertakan detail kesalahan.';
    }
}

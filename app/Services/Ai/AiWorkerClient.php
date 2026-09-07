<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Documents\Exceptions\DocumentIntelligenceFailed;
use App\Domain\Documents\Exceptions\DocumentOutputRejected;
use App\Domain\Documents\Exceptions\DocumentParsingFailed;
use App\Domain\Documents\Exceptions\NoExtractorAvailable;
use App\Domain\Documents\Exceptions\UnsupportedDocumentFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP menuju Python AI Worker (plan.md §26.2).
 *
 * Endpoint yang dipakai: health check, parsing deterministik berkas, klasifikasi dokumen
 * (§14.1), dan ekstraksi terstruktur (§14.2). Klien ini sengaja tidak mengetahui provider
 * AI apa pun, karena pemilihan provider berada di dalam worker (plan.md §13.3, §44.12).
 *
 * Pemetaan kode status HTTP menjadi exception adalah keputusan penting di kelas ini, karena
 * ia menentukan apa yang diretry queue:
 *
 * - 503 dan connection error → DocumentIntelligenceFailed, layak diretry;
 * - 415 → NoExtractorAvailable, mengulang tidak berguna;
 * - 422 → DocumentOutputRejected, mengulang menghasilkan penolakan yang sama.
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

    /**
     * Klasifikasi dokumen (plan.md §14.1).
     *
     * Halaman hasil parse dikirim sebagai teks, bukan berkasnya kembali. Halaman sudah
     * tersimpan sejak tahap parse, dan mengirim teks jauh lebih murah daripada mengirim
     * berkas serta memaksa worker memparse ulang (plan.md §13.2).
     *
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function classifyDocument(
        string $filename,
        ?string $mimeType,
        array $pages,
        ?string $businessContext = null,
    ): DocumentClassificationResult {
        $response = $this->send('classify', '/v1/classify', [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'business_context' => $businessContext,
            'pages' => $pages,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return DocumentClassificationResult::fromArray($payload);
    }

    /**
     * Ekstraksi terstruktur (plan.md §14.2).
     *
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function extractDocument(
        string $documentType,
        array $pages,
        ?string $businessContext = null,
    ): DocumentExtractionResult {
        $response = $this->send('extract', '/v1/extract', [
            'document_type' => $documentType,
            'business_context' => $businessContext,
            'pages' => $pages,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return DocumentExtractionResult::fromArray($payload);
    }

    /**
     * Klasifikasi peristiwa ekonomi (plan.md §14.3).
     *
     * @return array{event_code: string, confidence: string|float, reason: string, missing_information: array<int, string>}
     */
    public function classifyEconomicEvent(
        string $description,
        string $amount,
        string $direction,
        ?string $documentType = null,
        ?string $counterparty = null,
        ?string $businessContext = null,
    ): array {
        $response = $this->send('classify_event', '/v1/classify-event', [
            'description' => $description,
            'amount' => $amount,
            'direction' => $direction,
            'document_type' => $documentType,
            'counterparty' => $counterparty,
            'business_context' => $businessContext,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $missing = $payload['missing_information'] ?? [];

        return [
            'event_code' => (string) ($payload['event_code'] ?? ''),
            'confidence' => $payload['confidence'] ?? '0',
            'reason' => (string) ($payload['reason'] ?? ''),
            'missing_information' => is_array($missing)
                ? array_values(array_filter($missing, static fn (mixed $item): bool => is_string($item)))
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $task, string $endpoint, array $body): Response
    {
        try {
            $response = $this->request()->post($endpoint, $body);
        } catch (ConnectionException $exception) {
            throw DocumentIntelligenceFailed::workerUnreachable($task, $exception->getMessage());
        }

        $detail = $this->extractDetail($response->json());

        if ($response->status() === 415) {
            throw NoExtractorAvailable::forType(null, $detail);
        }

        if ($response->status() === 422) {
            throw DocumentOutputRejected::classification($detail);
        }

        if ($response->status() === 503) {
            throw DocumentIntelligenceFailed::providerUnavailable($task, $detail);
        }

        if ($response->failed()) {
            throw DocumentIntelligenceFailed::workerRejected($task, $response->status(), $detail);
        }

        return $response;
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

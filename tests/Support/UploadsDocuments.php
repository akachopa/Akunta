<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Services\DocumentProcessing\DocumentClassificationService;
use App\Services\DocumentProcessing\DocumentExtractionService;
use App\Services\DocumentProcessing\DocumentIngestionService;
use App\Services\DocumentProcessing\DocumentProcessingService;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Helper upload dokumen untuk test.
 *
 * Respons AI worker selalu di-fake: test Laravel tidak boleh bergantung pada proses
 * Python yang berjalan. Parser aslinya diuji terpisah oleh pytest di ai-worker.
 */
trait UploadsDocuments
{
    /**
     * Perilaku worker saat ini.
     *
     * Stub HTTP dipasang sekali dan membaca closure ini setiap request, karena
     * Http::fake() menumpuk stub dan yang terdaftar lebih dulu selalu menang. Tanpa
     * indireksi ini, skenario "gagal lalu berhasil setelah retry" tidak dapat dibuat.
     */
    private ?Closure $workerResponder = null;

    /**
     * Perilaku khusus per endpoint worker.
     *
     * Pipeline Phase 4 memanggil tiga endpoint berbeda dalam satu skenario, sehingga
     * responder tunggal tidak cukup: test "ekstraksi ditolak" tetap memerlukan parse dan
     * klasifikasi yang berhasil.
     *
     * @var array<string, Closure>
     */
    private array $workerRoutes = [];

    private bool $workerFakeInstalled = false;

    protected function fakeDocumentDisk(): void
    {
        Storage::fake((string) config('akunta.documents.disk', 'documents'));
    }

    /**
     * Respons parse yang berhasil dari worker.
     *
     * @param  array<int, array<string, mixed>>|null  $pages
     */
    protected function fakeParserSuccess(?array $pages = null, string $contentKind = 'table'): void
    {
        $pages ??= [
            [
                'page_number' => 1,
                'kind' => 'table',
                'label' => 'mutasi.csv',
                'text' => null,
                'rows' => [
                    ['Tanggal', 'Keterangan', 'Nominal'],
                    ['2026-01-15', 'SETORAN TUNAI', '2000000'],
                ],
                'needs_ocr' => false,
                'metadata' => ['row_count' => 2, 'delimiter' => ','],
            ],
        ];

        $needsOcr = collect($pages)->contains(fn (array $page): bool => (bool) ($page['needs_ocr'] ?? false));

        $this->fakeWorker(fn (): mixed => Http::response([
            'parser' => 'csv',
            'parser_version' => '1.0',
            'content_kind' => $contentKind,
            'page_count' => count($pages),
            'needs_ocr' => $needsOcr,
            'pages' => $pages,
            'metadata' => ['row_count' => 2],
        ]));
    }

    /**
     * Worker menolak berkas karena tidak ada parser untuknya (HTTP 415).
     */
    protected function fakeParserUnsupported(): void
    {
        $this->fakeWorker(fn (): mixed => Http::response(
            ['detail' => 'Tidak ada parser untuk berkas ini.'],
            415
        ));
    }

    /**
     * Worker menolak berkas karena isinya rusak (HTTP 422).
     */
    protected function fakeParserBrokenFile(): void
    {
        $this->fakeWorker(fn (): mixed => Http::response(['detail' => 'PDF tidak dapat dibaca.'], 422));
    }

    /**
     * Worker tidak dapat dihubungi sama sekali.
     */
    protected function fakeParserUnreachable(): void
    {
        $this->fakeWorker(function (): mixed {
            throw new ConnectionException('Connection refused');
        });
    }

    protected function csvFile(string $name = 'mutasi.csv'): File
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "Tanggal,Keterangan,Nominal\n2026-01-15,SETORAN TUNAI,2000000\n"
        );
    }

    protected function pdfFile(string $name = 'invoice.pdf'): File
    {
        // Header %PDF membuat MIME hasil deteksi isi berkas menjadi application/pdf,
        // sehingga aturan validasi mimetypes benar-benar teruji.
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"
        );
    }

    protected function imageFile(string $name = 'struk.png'): File
    {
        return UploadedFile::fake()->image($name, 60, 40);
    }

    protected function unsupportedFile(string $name = 'kontrak.docx'): File
    {
        return UploadedFile::fake()->create(
            $name,
            8,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
    }

    /**
     * Membuat dokumen lewat service ingestion, tanpa melewati HTTP layer.
     */
    protected function ingestDocument(
        Business $business,
        User $uploader,
        ?File $file = null,
        ?DocumentType $hint = null,
    ): Document {
        return app(DocumentIngestionService::class)->ingest(
            $business,
            $file ?? $this->csvFile(),
            $uploader,
            $hint
        );
    }

    /**
     * Respons klasifikasi yang berhasil (plan.md §14.1).
     *
     * @param  array<int, array{document_type: string, confidence: float}>  $alternatives
     */
    protected function fakeClassifierSuccess(
        string $documentType = 'purchase_invoice',
        float $confidence = 0.93,
        array $alternatives = [],
    ): void {
        $this->fakeWorkerRoute('/v1/classify', fn (): mixed => Http::response([
            'document_type' => $documentType,
            'confidence' => $confidence,
            'reason' => 'Memuat kata kunci faktur dan NPWP penerbit.',
            'alternatives' => $alternatives,
            'provider' => 'heuristic',
            'model' => 'rule-based-v1',
            'prompt_version' => 'classify_document/1',
            'latency_ms' => 12,
            'usage' => ['input_tokens' => 180, 'output_tokens' => 24, 'cost' => '0.000420', 'currency' => 'USD'],
            'raw' => ['document_type' => $documentType, 'confidence' => $confidence],
        ]));
    }

    /**
     * Worker menolak output classifier karena di luar taksonomi (HTTP 422).
     */
    protected function fakeClassifierOutputRejected(): void
    {
        $this->fakeWorkerRoute('/v1/classify', fn (): mixed => Http::response(
            ['detail' => 'Provider mengembalikan document_type di luar taksonomi.'],
            422
        ));
    }

    /**
     * Provider AI tidak tersedia (HTTP 503), satu-satunya kegagalan yang layak diretry.
     */
    protected function fakeClassifierUnavailable(): void
    {
        $this->fakeWorkerRoute('/v1/classify', fn (): mixed => Http::response(
            ['detail' => 'Provider openai tidak dapat dihubungi.'],
            503
        ));
    }

    /**
     * Respons ekstraksi terstruktur (plan.md §14.2).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $validationErrors
     */
    protected function fakeExtractorResponse(
        array $fields,
        array $rows = [],
        bool $valid = true,
        float $confidence = 0.91,
        array $validationErrors = [],
        string $extractor = 'invoice',
        string $schemaVersion = '1.0',
    ): void {
        $this->fakeWorkerRoute('/v1/extract', fn (): mixed => Http::response([
            'document_type' => 'purchase_invoice',
            'extractor' => $extractor,
            'schema_version' => $schemaVersion,
            'valid' => $valid,
            'validation_errors' => $validationErrors,
            'confidence' => $confidence,
            'fields' => $fields,
            'rows' => $rows,
            'provider' => 'heuristic',
            'model' => 'rule-based-v1',
            'prompt_version' => 'extract_document/1',
            'latency_ms' => 31,
            'usage' => ['input_tokens' => 420, 'output_tokens' => 96, 'cost' => '0.001250', 'currency' => 'USD'],
            'raw' => ['fields' => $fields, 'rows' => $rows],
        ]));
    }

    /**
     * Satu field hasil ekstraksi lengkap dengan buktinya.
     *
     * @return array<string, mixed>
     */
    protected function extractedField(
        string $key,
        ?string $value,
        float $confidence = 0.9,
        int $pageNumber = 1,
        ?string $sourceText = null,
    ): array {
        return [
            'key' => $key,
            'kind' => 'text',
            'value' => $value,
            'confidence' => $confidence,
            'page_number' => $pageNumber,
            'source_text' => $sourceText ?? sprintf('%s: %s', $key, (string) $value),
        ];
    }

    /**
     * Faktur yang aritmetikanya konsisten: subtotal + pajak = total.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function invoiceFields(
        string $subtotal = '1000000.00',
        string $tax = '110000.00',
        string $total = '1110000.00',
        string $date = '2026-02-10',
        float $confidence = 0.9,
    ): array {
        return [
            $this->extractedField('document_number', 'INV/2026/0012', $confidence),
            $this->extractedField('document_date', $date, $confidence),
            $this->extractedField('issuer_name', 'PT Sumber Kertas', $confidence),
            $this->extractedField('currency', 'IDR', $confidence),
            $this->extractedField('subtotal', $subtotal, $confidence),
            $this->extractedField('tax', $tax, $confidence),
            $this->extractedField('total', $total, $confidence),
        ];
    }

    /**
     * Worker tidak memiliki extractor untuk jenis dokumen ini (HTTP 415).
     */
    protected function fakeExtractorUnavailable(): void
    {
        $this->fakeWorkerRoute('/v1/extract', fn (): mixed => Http::response(
            ['detail' => 'Belum ada extractor untuk jenis dokumen payroll.'],
            415
        ));
    }

    /**
     * Menjalankan seluruh pipeline Phase 4 hingga selesai untuk satu dokumen.
     */
    protected function runIntelligencePipeline(Document $document): Document
    {
        app(TenantContext::class)->withBusiness($document->business, function () use ($document): void {
            app(DocumentProcessingService::class)->process($document);
            app(DocumentClassificationService::class)->classify($document);
            app(DocumentExtractionService::class)->extract($document);
        });

        return $document->refresh();
    }

    /**
     * @param  Closure(Request): mixed  $responder
     */
    private function fakeWorker(Closure $responder): void
    {
        $this->workerResponder = $responder;

        $this->installWorkerFake();
    }

    /**
     * @param  Closure(Request): mixed  $responder
     */
    private function fakeWorkerRoute(string $path, Closure $responder): void
    {
        $this->workerRoutes[$path] = $responder;

        $this->installWorkerFake();
    }

    private function installWorkerFake(): void
    {
        if ($this->workerFakeInstalled) {
            return;
        }

        Http::fake(function (Request $request): mixed {
            foreach ($this->workerRoutes as $path => $responder) {
                if (str_contains($request->url(), $path)) {
                    return $responder($request);
                }
            }

            if ($this->workerResponder === null) {
                return Http::response(['detail' => 'Endpoint worker tidak difake pada test ini.'], 500);
            }

            return ($this->workerResponder)($request);
        });

        $this->workerFakeInstalled = true;
    }
}

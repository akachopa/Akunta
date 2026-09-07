<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Models\User;
use App\Services\DocumentProcessing\DocumentIngestionService;
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
     * @param  Closure(Request): mixed  $responder
     */
    private function fakeWorker(Closure $responder): void
    {
        $this->workerResponder = $responder;

        if ($this->workerFakeInstalled) {
            return;
        }

        Http::fake(fn (Request $request): mixed => ($this->workerResponder)($request));

        $this->workerFakeInstalled = true;
    }
}

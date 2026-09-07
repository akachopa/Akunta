<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Exceptions\DocumentParsingFailed;
use App\Domain\Documents\Models\Document;
use App\Services\Audit\AuditLogger;
use App\Services\DocumentProcessing\DocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mengunduh berkas asli dokumen (plan.md §30 private object storage + signed URL).
 *
 * Dokumen finansial tidak pernah berada di public storage (plan.md §44.11), sehingga
 * berkas hanya dapat diakses melalui route ini setelah policy lolos.
 *
 * Pada object storage yang mendukungnya, user diarahkan ke signed URL berumur pendek agar
 * berkas besar tidak melewati PHP. Pada disk lokal yang tidak mendukung signed URL,
 * berkasnya distream langsung oleh aplikasi. Kedua jalur tetap melewati pemeriksaan
 * otorisasi yang sama.
 */
class DocumentDownloadController extends Controller
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(
        Request $request,
        Business $business,
        Document $document,
    ): RedirectResponse|StreamedResponse {
        $this->authorize('download', $document);

        $file = $document->originalFile;

        if ($file === null || ! $this->storage->exists($file)) {
            throw DocumentParsingFailed::missingOriginalFile();
        }

        /*
         * plan.md §31: akses ke dokumen finansial dicatat. Yang disimpan hanya identitas
         * dokumen dan checksum berkasnya, bukan isinya.
         */
        $this->audit->log(
            action: 'document.downloaded',
            entity: $document,
            after: [
                'reference' => $document->reference,
                'checksum_sha256' => $document->checksum_sha256,
            ],
            business: $business,
            actor: $request->user(),
        );

        $signedUrl = $this->storage->temporaryUrl($file);

        if ($signedUrl !== null) {
            return redirect()->away($signedUrl);
        }

        return $this->storage->download($file, $document->original_filename, $document->mime_type);
    }
}

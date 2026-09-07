<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\TenantContext;
use App\Services\DocumentProcessing\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Memproses satu dokumen di luar request (plan.md §27, §40 "upload async").
 *
 * Job menerima id, bukan model, karena serialisasi model akan memuat ulang baris tanpa
 * tenant context dan membuat global scope tenant menolak dokumennya. Business dimuat
 * lebih dulu, lalu seluruh pekerjaan dijalankan di dalam TenantContext bisnis tersebut
 * agar isolasi tenant tetap berlaku di queue worker (plan.md §44.15).
 *
 * ShouldBeUnique memenuhi plan.md §40 "duplicate job protection": satu dokumen tidak
 * diproses oleh dua worker sekaligus.
 */
class ProcessDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Kegagalan yang paling sering terjadi adalah worker belum siap menerima koneksi.
     * Backoff bertingkat memberi worker waktu pulih sebelum dokumen ditandai FAILED.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    public int $tries = 4;

    public function __construct(
        private readonly string $documentId,
        private readonly string $businessId,
    ) {}

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function handle(TenantContext $tenant, DocumentProcessingService $processing): void
    {
        $business = Business::query()->find($this->businessId);

        if (! $business instanceof Business) {
            Log::warning('Dokumen dilewati karena bisnisnya tidak ditemukan.', [
                'document_id' => $this->documentId,
                'business_id' => $this->businessId,
            ]);

            return;
        }

        $tenant->withBusiness($business, function () use ($processing): void {
            $document = Document::query()->find($this->documentId);

            if (! $document instanceof Document) {
                Log::warning('Dokumen tidak ditemukan saat diproses.', [
                    'document_id' => $this->documentId,
                ]);

                return;
            }

            $processing->process($document);
        });
    }

    /**
     * Dipanggil setelah seluruh percobaan habis.
     *
     * Status FAILED ditetapkan di sini, bukan pada percobaan pertama yang gagal, supaya
     * user tidak melihat dokumen ditandai gagal padahal masih akan dicoba otomatis.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $business = Business::query()->find($this->businessId);

        if (! $business instanceof Business) {
            return;
        }

        app(TenantContext::class)->withBusiness($business, function () use ($exception): void {
            $document = Document::query()->find($this->documentId);

            if ($document instanceof Document) {
                app(DocumentProcessingService::class)->markPermanentFailure($document, $exception);
            }
        });
    }
}

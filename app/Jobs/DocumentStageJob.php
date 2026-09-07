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
 * Basis job satu tahap pipeline dokumen (plan.md §27, §40).
 *
 * Ketiga tahap yang sudah dibangun memerlukan kerangka yang sama, dan kerangka itu bukan
 * formalitas:
 *
 * - **Id, bukan model.** Serialisasi model akan memuat ulang baris tanpa tenant context,
 *   dan global scope tenant akan menolak dokumennya (plan.md §44.15).
 * - **Tenant context di dalam worker.** Isolasi tenant harus tetap berlaku di luar request,
 *   jadi setiap tahap dijalankan di dalam context bisnis dokumennya.
 * - **Retry dengan backoff.** Kegagalan yang paling sering terjadi adalah worker AI belum
 *   siap menerima koneksi, dan itu pulih sendiri.
 * - **FAILED hanya di akhir.** Status gagal ditetapkan pada `failed()`, setelah seluruh
 *   percobaan habis, supaya user tidak melihat dokumen ditandai gagal padahal masih akan
 *   dicoba otomatis.
 *
 * ShouldBeUnique memenuhi plan.md §40 "duplicate job protection". Kunci uniknya adalah id
 * dokumen; Laravel sudah memisahkan kunci per kelas job, sehingga tahap yang berbeda atas
 * dokumen yang sama tidak saling memblokir.
 */
abstract class DocumentStageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    public int $tries = 4;

    public function __construct(
        public readonly string $documentId,
        public readonly string $businessId,
    ) {}

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function handle(TenantContext $tenant): void
    {
        $business = Business::query()->find($this->businessId);

        if (! $business instanceof Business) {
            Log::warning('Dokumen dilewati karena bisnisnya tidak ditemukan.', [
                'document_id' => $this->documentId,
                'business_id' => $this->businessId,
                'stage_job' => static::class,
            ]);

            return;
        }

        $tenant->withBusiness($business, function (): void {
            $document = Document::query()->find($this->documentId);

            if (! $document instanceof Document) {
                Log::warning('Dokumen tidak ditemukan saat diproses.', [
                    'document_id' => $this->documentId,
                    'stage_job' => static::class,
                ]);

                return;
            }

            $this->run($document);
        });
    }

    /**
     * Dipanggil setelah seluruh percobaan habis.
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

    abstract protected function run(Document $document): void;
}

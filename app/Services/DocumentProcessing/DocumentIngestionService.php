<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Enums\DocumentFileKind;
use App\Domain\Documents\Enums\DocumentSourceType;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menerima berkas unggahan menjadi dokumen inbox (plan.md §5.2, §37 Phase 3).
 *
 * Urutannya penting: berkas ditulis ke storage lebih dulu, lalu baris database dibuat di
 * dalam satu transaksi. Bila transaksi gagal, yang tertinggal hanya berkas tanpa
 * referensi, bukan dokumen tanpa berkas. Dokumen tanpa berkas akan melanggar acceptance
 * "original file tetap tersimpan" dan tidak dapat diperbaiki.
 *
 * plan.md §40 mewajibkan upload asinkron: service ini hanya menyimpan dan mengantre.
 * Tidak ada parsing yang dijalankan di dalam request.
 */
class DocumentIngestionService
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentReferenceGenerator $references,
        private readonly DocumentProcessingService $processing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Menerima satu berkas.
     */
    public function ingest(
        Business $business,
        UploadedFile $file,
        User $uploader,
        ?DocumentType $documentTypeHint = null,
    ): Document {
        $stored = $this->storage->storeUpload($business, $file);

        $document = DB::transaction(function () use ($business, $file, $uploader, $documentTypeHint, $stored): Document {
            $document = Document::query()->create([
                'business_id' => $business->getKey(),
                'reference' => $this->references->next($business),
                'uploaded_by' => $uploader->getKey(),
                'source_type' => DocumentSourceType::Upload,
                'processing_status' => DocumentStatus::Uploaded,

                /*
                 * plan.md §5.2: user tidak wajib menentukan jenis dokumen. Bila ia
                 * memberi petunjuk, asalnya dicatat sebagai `user` supaya classifier
                 * Phase 4 tahu bahwa nilainya bukan hasil tebakan model.
                 */
                'document_type' => $documentTypeHint,
                'document_type_source' => $documentTypeHint === null ? null : 'user',

                'original_filename' => $this->sanitizeFilename($file->getClientOriginalName()),
                'file_extension' => Str::lower($file->getClientOriginalExtension()),
                'mime_type' => $file->getClientMimeType(),
                'byte_size' => $stored['byte_size'],
                'checksum_sha256' => $stored['checksum_sha256'],
                'uploaded_at' => Carbon::now(),
            ]);

            $document->files()->create([
                'business_id' => $business->getKey(),
                'kind' => DocumentFileKind::Original,
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'mime_type' => $file->getClientMimeType(),
                'byte_size' => $stored['byte_size'],
                'checksum_sha256' => $stored['checksum_sha256'],
            ]);

            /*
             * plan.md §31: upload dokumen finansial adalah operasi sensitif. Isi berkas
             * tidak ikut dicatat; yang dicatat hanya identitas dan checksum-nya.
             */
            $this->audit->log(
                action: 'document.uploaded',
                entity: $document,
                after: [
                    'reference' => $document->reference,
                    'original_filename' => $document->original_filename,
                    'mime_type' => $document->mime_type,
                    'byte_size' => $document->byte_size,
                    'checksum_sha256' => $document->checksum_sha256,
                    'document_type' => $document->document_type?->value,
                ],
                business: $business,
                actor: $uploader,
            );

            return $document;
        });

        $this->processing->queue($document);

        return $document->refresh();
    }

    /**
     * Menerima beberapa berkas sekaligus (plan.md §37 Phase 3 "multi-file upload").
     *
     * Setiap berkas berdiri sendiri: satu berkas yang gagal tidak boleh menggagalkan
     * berkas lain yang sudah tersimpan, karena user harus dapat melihat mana yang masuk
     * dan mana yang perlu diunggah ulang.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{documents: array<int, Document>, failures: array<int, array{filename: string, message: string}>}
     */
    public function ingestMany(
        Business $business,
        array $files,
        User $uploader,
        ?DocumentType $documentTypeHint = null,
    ): array {
        $documents = [];
        $failures = [];

        foreach ($files as $file) {
            try {
                $documents[] = $this->ingest($business, $file, $uploader, $documentTypeHint);
            } catch (Throwable $exception) {
                report($exception);

                $failures[] = [
                    'filename' => $file->getClientOriginalName(),
                    'message' => 'Berkas gagal disimpan. Silakan unggah ulang.',
                ];
            }
        }

        return ['documents' => $documents, 'failures' => $failures];
    }

    /**
     * Nama asli dipertahankan untuk ditampilkan ke user, tetapi komponen path dibuang
     * supaya nama berkas tidak pernah dapat dibaca sebagai path.
     */
    private function sanitizeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));

        return Str::limit(trim($filename), 250, '');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Business\Models\Business;
use App\Domain\Documents\Exceptions\DocumentParsingFailed;
use App\Domain\Documents\Models\DocumentFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penyimpanan berkas dokumen pada private object storage (plan.md §30, §44.11).
 *
 * Berkas disimpan dengan nama acak, bukan nama asli dari user, karena nama asli dapat
 * memuat path traversal maupun informasi yang tidak seharusnya muncul di key storage.
 * Nama asli tetap tersimpan pada kolom `documents.original_filename`.
 *
 * Tidak ada method di kelas ini yang menghasilkan URL publik. Akses berkas hanya lewat
 * signed URL berumur pendek atau streaming terautentikasi.
 */
class DocumentStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('akunta.documents.disk', 'documents');
    }

    /**
     * Menyimpan berkas unggahan dan mengembalikan metadatanya.
     *
     * @return array{disk: string, path: string, byte_size: int, checksum_sha256: string}
     */
    public function storeUpload(Business $business, UploadedFile $file): array
    {
        $checksum = hash_file('sha256', $file->getRealPath());

        if ($checksum === false) {
            throw DocumentParsingFailed::missingOriginalFile();
        }

        $path = $this->buildPath($business, $file->getClientOriginalExtension() ?: $file->extension());

        $this->disk()->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private']
        );

        return [
            'disk' => $this->diskName(),
            'path' => $path,
            'byte_size' => $file->getSize() ?: 0,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * Membaca isi berkas untuk dikirim ke parser.
     */
    public function read(DocumentFile $file): string
    {
        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            throw DocumentParsingFailed::missingOriginalFile();
        }

        $contents = $disk->get($file->path);

        if ($contents === null) {
            throw DocumentParsingFailed::missingOriginalFile();
        }

        return $contents;
    }

    public function exists(DocumentFile $file): bool
    {
        return Storage::disk($file->disk)->exists($file->path);
    }

    /**
     * Streaming berkas melalui aplikasi.
     *
     * Dipakai sebagai fallback untuk disk yang tidak mendukung signed URL. Disk dibaca
     * dari baris berkasnya, bukan dari konfigurasi saat ini, supaya dokumen lama tetap
     * dapat diunduh setelah disk default berganti.
     */
    public function download(DocumentFile $file, string $filename, string $mimeType): StreamedResponse
    {
        return Storage::disk($file->disk)->download($file->path, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }

    /**
     * Signed URL berumur pendek untuk mengunduh berkas (plan.md §30).
     *
     * Mengembalikan null pada driver yang tidak mendukung temporary URL, misalnya disk
     * lokal pada environment pengembangan. Pemanggil harus menyediakan fallback berupa
     * streaming terautentikasi, bukan membuka berkas ke publik.
     */
    public function temporaryUrl(DocumentFile $file, ?int $ttlSeconds = null): ?string
    {
        $ttl = $ttlSeconds ?? (int) config('akunta.documents.signed_url_ttl', 300);

        try {
            return Storage::disk($file->disk)->temporaryUrl($file->path, Carbon::now()->addSeconds($ttl));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Key storage dipartisi per bisnis dan per bulan agar satu prefix tidak menampung
     * seluruh dokumen platform, dan agar retensi per bisnis dapat dijalankan nanti.
     */
    private function buildPath(Business $business, ?string $extension): string
    {
        $extension = Str::lower((string) $extension);
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

        $name = (string) Str::uuid();

        if ($extension !== '') {
            $name .= '.' . $extension;
        }

        return sprintf(
            'businesses/%s/documents/%s/%s',
            $business->getKey(),
            Carbon::now()->format('Y/m'),
            $name
        );
    }
}

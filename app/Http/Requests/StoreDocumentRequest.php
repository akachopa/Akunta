<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Documents\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Validasi upload dokumen (plan.md §3.1, §30, §37 Phase 3).
 *
 * plan.md §30 mewajibkan file-size limit dan MIME validation. Keduanya dibaca dari
 * config/akunta.php supaya batasnya tidak tersebar sebagai magic number dan dapat
 * disesuaikan per environment.
 */
class StoreDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $mimeTypes */
        $mimeTypes = (array) config('akunta.documents.allowed_mime_types', []);
        $maxSizeKb = (int) config('akunta.documents.max_upload_size_kb', 25600);

        return [
            // plan.md §37 Phase 3: multi-file upload. Satu berkas pun dikirim sebagai array
            // agar tidak ada dua jalur upload yang berbeda perilakunya.
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'required',
                'file',
                'max:' . $maxSizeKb,

                /*
                 * `mimetypes` memeriksa tipe hasil deteksi isi berkas, bukan ekstensi
                 * maupun header Content-Type dari klien, sehingga berkas yang diganti
                 * ekstensinya tetap tertolak.
                 */
                'mimetypes:' . implode(',', $mimeTypes),

                // Ekstensi tetap diperiksa supaya router parser di worker mendapat
                // ekstensi yang dikenalnya.
                'extensions:pdf,csv,txt,xls,xlsx,jpg,jpeg,png',
            ],

            // plan.md §5.2: user tidak wajib menentukan jenis dokumen.
            'document_type' => ['nullable', Rule::in(array_map(
                static fn (DocumentType $type): string => $type->value,
                DocumentType::selectableOnUpload()
            ))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => 'Pilih minimal satu berkas untuk diunggah.',
            'files.*.mimetypes' => 'Tipe berkas tidak didukung. Unggah PDF, Excel, CSV, JPG, atau PNG.',
            'files.*.extensions' => 'Ekstensi berkas tidak didukung. Unggah PDF, Excel, CSV, JPG, atau PNG.',
            'files.*.max' => 'Ukuran berkas melebihi batas yang diizinkan.',
        ];
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function uploadedFiles(): array
    {
        /** @var array<int, UploadedFile> $files */
        $files = array_values(array_filter(
            (array) $this->file('files'),
            static fn (mixed $file): bool => $file instanceof UploadedFile
        ));

        return $files;
    }

    public function documentTypeHint(): ?DocumentType
    {
        $value = $this->input('document_type');

        return is_string($value) ? DocumentType::tryFrom($value) : null;
    }
}

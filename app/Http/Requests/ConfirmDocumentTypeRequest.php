<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Documents\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reviewer menetapkan jenis dokumen (plan.md §16.2, §17.1).
 *
 * `unknown` tidak dapat dipilih: itu hasil klasifikasi ketika model tidak yakin, bukan
 * pernyataan manusia. Reviewer yang tidak tahu jenis dokumennya cukup membiarkannya di
 * antrean review.
 */
class ConfirmDocumentTypeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(array_map(
                static fn (DocumentType $type): string => $type->value,
                array_values(DocumentType::selectableOnUpload())
            ))],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Pilih jenis dokumennya.',
            'document_type.in' => 'Jenis dokumen tidak dikenali.',
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::from((string) $this->input('document_type'));
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}

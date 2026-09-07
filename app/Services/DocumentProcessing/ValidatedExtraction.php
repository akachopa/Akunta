<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Enums\ExtractionStatus;

/**
 * Verdict Laravel atas satu hasil ekstraksi (plan.md §37 Phase 4).
 *
 * Ketika ditolak, `fields` selalu kosong. Itu bukan penyederhanaan: menyimpan sebagian
 * field dari ekstraksi yang ditolak akan menghasilkan dokumen yang tampak berisi data sah
 * padahal angkanya tidak konsisten, dan phase berikutnya tidak memiliki cara mengetahui
 * bahwa nilai itu berasal dari hasil yang gagal. Buktinya tetap tersimpan sebagai raw
 * output pada `document_extractions`.
 */
final class ValidatedExtraction
{
    /**
     * @param  array<int, PreparedField>  $fields
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $errors,
    ) {}

    public function isAccepted(): bool
    {
        return $this->errors === [];
    }

    public function status(): ExtractionStatus
    {
        return $this->isAccepted() ? ExtractionStatus::Accepted : ExtractionStatus::Rejected;
    }

    /**
     * Field tingkat dokumen, terindeks oleh kunci field.
     *
     * @return array<string, PreparedField>
     */
    public function documentFields(): array
    {
        $fields = [];

        foreach ($this->fields as $field) {
            if ($field->rowIndex === null) {
                $fields[$field->key->value] = $field;
            }
        }

        return $fields;
    }
}

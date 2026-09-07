<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

use App\Domain\Documents\Enums\DocumentFieldKey;
use App\Domain\Documents\Models\Document;

/**
 * Permintaan review tidak dapat dikerjakan atas dokumen ini.
 *
 * Bukan soal hak akses — itu ditangani policy — melainkan soal keadaan dokumennya: tidak
 * ada ekstraksi yang diterima, field yang dikoreksi tidak pernah diekstraksi, atau nilai
 * koreksinya sendiri tidak sah. Ketiganya diangkat sebagai exception domain supaya HTTP
 * layer memetakannya menjadi 422 tanpa mengenali kasusnya satu per satu.
 */
final class DocumentReviewRejected extends DocumentException
{
    public static function withoutAcceptedExtraction(Document $document): self
    {
        return new self(sprintf(
            'Dokumen %s belum memiliki hasil ekstraksi yang lolos validasi, sehingga datanya belum dapat dikoreksi.',
            $document->reference
        ));
    }

    public static function unknownField(DocumentFieldKey $key): self
    {
        return new self(sprintf(
            '%s tidak termasuk data yang dibaca dari dokumen ini.',
            $key->label()
        ));
    }

    public static function unrecognizedField(string $key): self
    {
        return new self(sprintf(
            'Field [%s] tidak dikenali sebagai data dokumen.',
            mb_strimwidth($key, 0, 48, '…')
        ));
    }

    public static function invalidValue(DocumentFieldKey $key): self
    {
        return new self(sprintf(
            'Nilai %s tidak dapat dibaca sebagai %s.',
            $key->label(),
            $key->kind()->label()
        ));
    }

    public static function notReviewable(Document $document): self
    {
        return new self(sprintf(
            'Dokumen %s sedang dalam proses (%s), sehingga belum dapat direview.',
            $document->reference,
            $document->processing_status->label()
        ));
    }
}

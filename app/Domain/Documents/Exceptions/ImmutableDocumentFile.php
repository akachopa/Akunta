<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

use App\Domain\Documents\Models\DocumentFile;

/**
 * plan.md §37 Phase 3: berkas asli yang diunggah harus tetap tersimpan.
 * plan.md §45.12: source traceability sampai file asli tidak boleh terputus.
 */
final class ImmutableDocumentFile extends DocumentException
{
    public static function forMutation(DocumentFile $file): self
    {
        return new self(sprintf(
            'Berkas dokumen [%s] bersifat immutable. Unggah dokumen baru bila berkasnya perlu diganti.',
            $file->path
        ));
    }
}

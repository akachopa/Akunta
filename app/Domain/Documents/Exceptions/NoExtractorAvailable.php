<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

use App\Domain\Documents\Enums\DocumentType;

/**
 * Jenis dokumen dikenali, tetapi belum ada extractor untuknya (plan.md §37 Phase 4).
 *
 * Phase 4 membangun empat extractor sesuai plan.md §28. Jenis di luar keempatnya berhenti
 * setelah klasifikasi dan menunggu manusia. Memaksakan schema faktur pada slip gaji akan
 * menghasilkan field yang terisi tetapi salah arti, dan itu jauh lebih berbahaya daripada
 * dokumen yang jujur menunggu.
 */
final class NoExtractorAvailable extends DocumentException
{
    public static function forType(?DocumentType $documentType, string $detail): self
    {
        return new self(sprintf(
            'Belum ada extractor untuk jenis dokumen %s. %s',
            $documentType?->label() ?? 'yang tidak dikenali',
            $detail
        ));
    }
}

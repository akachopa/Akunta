<?php

declare(strict_types=1);

namespace App\Services\TransactionNormalization;

use App\Domain\Documents\Enums\DocumentType;

/**
 * Kontrak normalizer transaksi (plan.md §13.1 TRANSACTION GENERATOR, §37 Phase 5).
 *
 * Satu normalizer per bentuk sumber, bukan satu normalizer besar bercabang. Bentuk sumber
 * menentukan seluruh perilakunya: rekening koran menghasilkan banyak transaksi dari
 * barisnya, faktur menghasilkan satu transaksi dari nilai dokumennya, dan settlement
 * menghasilkan satu transaksi kas beserta angka pendukung yang tidak boleh dicampur ke
 * dalamnya. Menyatukannya akan menghasilkan percabangan yang setiap phase berikutnya harus
 * ikut pahami.
 *
 * Normalizer bersifat deterministik: tidak ada panggilan model di dalamnya. Yang ditafsirkan
 * model adalah jenis dokumen dan nilai field-nya (Phase 4), sedangkan makna ekonominya
 * ditafsirkan Phase 8. Di antara keduanya tidak boleh ada tebakan.
 */
interface DocumentNormalizer
{
    public function supports(DocumentType $documentType): bool;

    public function normalize(ExtractedDocument $source): NormalizationResult;
}

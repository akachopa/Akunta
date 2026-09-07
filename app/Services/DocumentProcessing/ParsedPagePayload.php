<?php

declare(strict_types=1);

namespace App\Services\DocumentProcessing;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPage;

/**
 * Membentuk payload halaman yang dikirim ke AI worker (plan.md §14.1, §14.2).
 *
 * Klasifikasi dan ekstraksi memakai halaman hasil parse, bukan berkas aslinya. Halaman
 * sudah tersimpan sejak tahap parse, sehingga mengirim teksnya jauh lebih murah daripada
 * mengunggah berkas dan memaksa worker memparse ulang untuk setiap tahap (plan.md §13.2
 * "gunakan model termurah yang cukup" berlaku juga untuk lalu lintasnya).
 */
final class ParsedPagePayload
{
    private function __construct() {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forDocument(Document $document): array
    {
        return $document->pages()
            ->orderBy('page_number')
            ->get()
            ->map(static fn (DocumentPage $page): array => [
                'page_number' => $page->page_number,
                'kind' => $page->kind->value,
                'label' => $page->label,
                'text' => $page->text,
                'rows' => $page->rows,
            ])
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

/**
 * Provider merespons, tetapi outputnya melanggar kontrak (plan.md §37 Phase 4).
 *
 * Bukan kegagalan sistem: worker berjalan, model menjawab, jawabannya saja yang tidak dapat
 * dipakai. Karena itu tidak diretry — mengulang panggilan yang sama dengan masukan yang
 * sama akan menghasilkan penolakan yang sama, dan hanya membakar token.
 *
 * Dokumennya diteruskan ke reviewer beserta bukti penolakannya, sehingga manusia dapat
 * memutuskan alih-alih pipeline mencoba selamanya.
 */
final class DocumentOutputRejected extends DocumentException
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        string $message,
        public readonly array $reasons = [],
    ) {
        parent::__construct($message);
    }

    public static function classification(string $detail): self
    {
        return new self(
            'Output klasifikasi dokumen tidak sesuai kontrak: ' . $detail,
            [$detail]
        );
    }

    /**
     * @param  array<int, string>  $reasons
     */
    public static function extraction(array $reasons): self
    {
        return new self(
            'Hasil ekstraksi tidak lolos validasi: ' . implode(' ', $reasons),
            $reasons
        );
    }
}

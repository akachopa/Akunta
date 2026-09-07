<?php

declare(strict_types=1);

namespace App\Services\EntityResolution;

/**
 * Normalisasi nama entity (plan.md §9.3).
 *
 * Bentuk badan usaha dan tanda baca dibuang supaya "PT Sumber Makmur, Tbk" dan
 * "SUMBER MAKMUR" bertemu pada pencocokan deterministik.
 */
final class EntityNameNormalizer
{
    /**
     * @var array<int, string>
     */
    private const LEGAL_TOKENS = [
        'pt', 'cv', 'ud', 'tbk', 'pd', 'tb', 'inc', 'ltd', 'co', 'corp', 'llc',
        'persero', 'terbuka',
    ];

    /**
     * @var array<int, string>
     */
    private const BANK_NOISE = [
        'trsf', 'transfer', 'biaya', 'admin', 'setoran', 'tunai', 'qris', 'va',
        'atm', 'debit', 'kredit', 'payment', 'pembayaran', 'dari', 'ke', 'by',
        'bank', 'mandiri', 'bca', 'bni', 'bri', 'btn', 'cimb', 'danamon',
        'mutasi', 'rekening',
    ];

    public function normalize(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = str_replace(['.', ',', ';', ':', '/', '\\', '-', '_', "'", '"'], ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        $tokens = array_values(array_filter(
            explode(' ', $value),
            fn (string $token): bool => $token !== '' && ! in_array($token, self::LEGAL_TOKENS, true)
        ));

        return trim(implode(' ', $tokens));
    }

    public function normalizeIdentifier(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s.\-]/u', '', trim($value)));
    }

    /**
     * Mengambil kandidat nama dari keterangan mutasi bank.
     *
     * Phase 5 sengaja tidak menebak nama dari keterangan. Di sini tebakannya boleh
     * karena hasilnya entity yang dapat dikonfirmasi atau digabung manusia, bukan
     * nilai yang langsung menjadi jurnal.
     */
    public function fromBankDescription(string $description): ?string
    {
        $value = mb_strtolower($description);
        $value = (string) preg_replace('/\d+/u', ' ', $value);
        $value = str_replace(['.', ',', ';', ':', '/', '\\', '-', '_'], ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        $tokens = array_values(array_filter(
            explode(' ', $value),
            function (string $token): bool {
                if ($token === '' || in_array($token, self::LEGAL_TOKENS, true)) {
                    return false;
                }

                return ! in_array($token, self::BANK_NOISE, true);
            }
        ));

        $candidate = trim(implode(' ', $tokens));

        if (mb_strlen($candidate) < 3) {
            return null;
        }

        return mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tab daftar transaksi.
 *
 * plan.md §40 mewajibkan penyaringan di sisi server, sehingga penyaringannya berada di sini
 * dan bukan di frontend. Satu rekening koran dapat menghasilkan ratusan transaksi, dan
 * memuat semuanya ke browser hanya untuk menyaringnya di sana akan membuat halaman ini
 * melambat tepat pada bisnis yang paling banyak memakainya.
 *
 * Tab-nya mengikuti apa yang benar-benar dapat dilakukan user pada Phase 5. "Perlu
 * Informasi" memuat transaksi yang nominalnya terbaca tetapi arah atau maknanya belum
 * dapat dipastikan (plan.md §16.2); "Ditolak" tetap tampil karena plan.md §31 melarang
 * jejak keputusan disembunyikan.
 */
enum TransactionFilter: string
{
    case All = 'all';
    case NeedInformation = 'need-information';
    case Posted = 'posted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Semua Transaksi',
            self::NeedInformation => 'Perlu Informasi',
            self::Posted => 'Terposting',
            self::Rejected => 'Ditolak',
        };
    }

    /**
     * @param  Builder<\App\Domain\Transactions\Models\Transaction>  $query
     * @return Builder<\App\Domain\Transactions\Models\Transaction>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            /*
             * "Semua" mengecualikan yang ditolak, sejalan dengan inbox dokumen yang
             * mengecualikan arsip: keduanya punya tab tersendiri, dan menampilkannya di dua
             * tempat membuat jumlah yang dilihat user tidak konsisten.
             */
            self::All => $query->whereNot('status', TransactionStatus::Rejected->value),

            self::NeedInformation => $query->withStatus(TransactionStatus::NeedReview),
            self::Posted => $query->withStatus(TransactionStatus::Posted),
            self::Rejected => $query->withStatus(TransactionStatus::Rejected),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

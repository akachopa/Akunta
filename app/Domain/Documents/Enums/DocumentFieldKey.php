<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Field canonical hasil ekstraksi (plan.md §14.2).
 *
 * Enum ini adalah kontrak antara AI worker dan Laravel. Worker mengembalikan kunci field
 * sebagai string, dan hanya kunci yang terdaftar di sini yang diterima: field karangan
 * tidak boleh tersimpan sebagai data akuntansi, dan enum inilah yang menahannya.
 *
 * Lima kunci terakhir milik baris mutasi rekening koran, bukan field tingkat dokumen.
 * Keduanya berada pada satu enum karena keduanya tersimpan di `document_fields`, dibedakan
 * oleh `row_index` (plan.md §8.4 `row_reference`).
 */
enum DocumentFieldKey: string
{
    case DocumentNumber = 'document_number';
    case DocumentDate = 'document_date';
    case DueDate = 'due_date';
    case IssuerName = 'issuer_name';
    case BuyerName = 'buyer_name';
    case MerchantName = 'merchant_name';
    case MerchantId = 'merchant_id';
    case Currency = 'currency';
    case Subtotal = 'subtotal';
    case Tax = 'tax';
    case Total = 'total';
    case PaymentMethod = 'payment_method';

    case BankName = 'bank_name';
    case AccountNumber = 'account_number';
    case AccountHolder = 'account_holder';
    case PeriodStart = 'period_start';
    case PeriodEnd = 'period_end';
    case OpeningBalance = 'opening_balance';
    case ClosingBalance = 'closing_balance';

    case SettlementDate = 'settlement_date';
    case GrossAmount = 'gross_amount';
    case MdrFee = 'mdr_fee';
    case NetAmount = 'net_amount';
    case TransactionCount = 'transaction_count';

    case RowDate = 'date';
    case RowDescription = 'description';
    case RowDebit = 'debit';
    case RowCredit = 'credit';
    case RowBalance = 'balance';

    public function kind(): DocumentFieldKind
    {
        return match ($this) {
            self::DocumentDate,
            self::DueDate,
            self::PeriodStart,
            self::PeriodEnd,
            self::SettlementDate,
            self::RowDate => DocumentFieldKind::Date,

            self::Subtotal,
            self::Tax,
            self::Total,
            self::OpeningBalance,
            self::ClosingBalance,
            self::GrossAmount,
            self::MdrFee,
            self::NetAmount,
            self::RowDebit,
            self::RowCredit,
            self::RowBalance => DocumentFieldKind::Money,

            self::TransactionCount => DocumentFieldKind::Integer,

            default => DocumentFieldKind::Text,
        };
    }

    /**
     * Label bahasa Indonesia untuk UI review.
     *
     * plan.md §16.3 melarang pertanyaan teknis ke user, jadi field ditampilkan dengan nama
     * yang dikenal pemilik usaha, bukan nama kunci teknisnya.
     */
    public function label(): string
    {
        return match ($this) {
            self::DocumentNumber => 'Nomor Dokumen',
            self::DocumentDate => 'Tanggal Dokumen',
            self::DueDate => 'Jatuh Tempo',
            self::IssuerName => 'Penerbit',
            self::BuyerName => 'Pembeli',
            self::MerchantName => 'Nama Merchant',
            self::MerchantId => 'ID Merchant',
            self::Currency => 'Mata Uang',
            self::Subtotal => 'Subtotal',
            self::Tax => 'Pajak',
            self::Total => 'Total',
            self::PaymentMethod => 'Metode Pembayaran',
            self::BankName => 'Nama Bank',
            self::AccountNumber => 'Nomor Rekening',
            self::AccountHolder => 'Nama Pemilik Rekening',
            self::PeriodStart => 'Awal Periode',
            self::PeriodEnd => 'Akhir Periode',
            self::OpeningBalance => 'Saldo Awal',
            self::ClosingBalance => 'Saldo Akhir',
            self::SettlementDate => 'Tanggal Settlement',
            self::GrossAmount => 'Nilai Bruto',
            self::MdrFee => 'Biaya MDR',
            self::NetAmount => 'Nilai Neto',
            self::TransactionCount => 'Jumlah Transaksi',
            self::RowDate => 'Tanggal',
            self::RowDescription => 'Keterangan',
            self::RowDebit => 'Debit',
            self::RowCredit => 'Kredit',
            self::RowBalance => 'Saldo',
        };
    }

    /**
     * Kolom `documents` yang diproyeksikan dari field ini (plan.md §8.1).
     *
     * Beberapa field berbeda memetakan ke kolom yang sama karena bentuk canonical-nya
     * memang satu: tanggal settlement QRIS dan akhir periode rekening koran keduanya
     * adalah "tanggal dokumen", dan nilai bruto QRIS adalah nilai total dokumennya.
     * Pemetaan itu membuat inbox dapat menampilkan tanggal serta nilai dokumen apa pun
     * jenisnya tanpa mengetahui schema tiap extractor.
     */
    public function canonicalColumn(): ?string
    {
        return match ($this) {
            self::DocumentDate, self::SettlementDate, self::PeriodEnd => 'document_date',
            self::Currency => 'currency',
            self::Subtotal => 'subtotal',
            self::Tax => 'tax',
            self::Total, self::GrossAmount => 'total',
            default => null,
        };
    }

    /**
     * Field yang menjadi bagian baris mutasi, bukan field tingkat dokumen.
     */
    public function isRowField(): bool
    {
        return in_array(
            $this,
            [self::RowDate, self::RowDescription, self::RowDebit, self::RowCredit, self::RowBalance],
            true
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<int, self>
     */
    public static function rowFields(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->isRowField()
        ));
    }
}

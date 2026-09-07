<?php

declare(strict_types=1);

namespace App\Domain\Entities\Enums;

/**
 * Jenis identitas entity (plan.md §9.3).
 */
enum EntityIdentifierKind: string
{
    case TaxId = 'tax_id';
    case BankAccount = 'bank_account';
    case Phone = 'phone';
    case Email = 'email';
    case Address = 'address';
    case MerchantId = 'merchant_id';

    public function label(): string
    {
        return match ($this) {
            self::TaxId => 'NPWP',
            self::BankAccount => 'Nomor Rekening',
            self::Phone => 'Telepon',
            self::Email => 'Email',
            self::Address => 'Alamat',
            self::MerchantId => 'ID Merchant',
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

<?php

declare(strict_types=1);

namespace App\Domain\Entities\Enums;

/**
 * Jenis entity master (plan.md §9.1).
 */
enum EntityType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Employee = 'employee';
    case Owner = 'owner';
    case Bank = 'bank';
    case Government = 'government';
    case Marketplace = 'marketplace';
    case PaymentGateway = 'payment_gateway';
    case Platform = 'platform';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Pelanggan',
            self::Supplier => 'Pemasok',
            self::Employee => 'Karyawan',
            self::Owner => 'Pemilik',
            self::Bank => 'Bank',
            self::Government => 'Instansi Pemerintah',
            self::Marketplace => 'Marketplace',
            self::PaymentGateway => 'Payment Gateway',
            self::Platform => 'Platform',
            self::Other => 'Lainnya',
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

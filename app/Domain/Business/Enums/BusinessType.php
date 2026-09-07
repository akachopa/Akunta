<?php

declare(strict_types=1);

namespace App\Domain\Business\Enums;

/**
 * Jenis usaha awal (plan.md §5.1).
 *
 * Jenis usaha menentukan COA template starter yang di-generate saat onboarding.
 */
enum BusinessType: string
{
    case Retail = 'retail';
    case Restaurant = 'restaurant';
    case Service = 'service';
    case Distributor = 'distributor';
    case Contractor = 'contractor';
    case Workshop = 'workshop';
    case Laundry = 'laundry';
    case Travel = 'travel';
    case ProfessionalService = 'professional_service';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Retail',
            self::Restaurant => 'Restoran / F&B',
            self::Service => 'Jasa',
            self::Distributor => 'Distributor',
            self::Contractor => 'Kontraktor',
            self::Workshop => 'Workshop / Bengkel',
            self::Laundry => 'Laundry',
            self::Travel => 'Travel',
            self::ProfessionalService => 'Jasa Profesional',
            self::General => 'Umum / Lainnya',
        };
    }

    /**
     * Bisnis yang menahan persediaan memerlukan akun inventory pada COA starter-nya.
     */
    public function holdsInventory(): bool
    {
        return match ($this) {
            self::Retail, self::Restaurant, self::Distributor, self::Workshop => true,
            default => false,
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

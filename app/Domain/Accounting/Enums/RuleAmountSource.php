<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Sumber nominal baris accounting rule.
 *
 * Settlement QRIS memuat tiga angka yang berbeda artinya (plan.md §19.2). Rule harus
 * dapat merujuk bruto, MDR, atau neto secara eksplisit, bukan selalu memakai nominal
 * transaksi — yang pada Phase 5 adalah neto.
 */
enum RuleAmountSource: string
{
    case Transaction = 'transaction';
    case GrossAmount = 'gross_amount';
    case MdrFee = 'mdr_fee';
    case NetAmount = 'net_amount';
    case Tax = 'tax';
    case Subtotal = 'subtotal';

    public function isOptional(): bool
    {
        return match ($this) {
            self::Transaction => false,
            self::GrossAmount, self::MdrFee, self::NetAmount, self::Tax, self::Subtotal => true,
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

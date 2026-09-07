<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Exceptions\InvalidJournalLine;
use App\Support\Money;

/**
 * Satu baris journal yang diminta pemanggil.
 *
 * Nilai debit/kredit selalu string desimal (plan.md §44.14 melarang float untuk uang).
 */
final class JournalLineData
{
    public readonly string $debit;

    public readonly string $credit;

    public function __construct(
        public readonly string $accountId,
        int|string $debit = '0',
        int|string $credit = '0',
        public readonly ?string $description = null,
        public readonly ?string $entityId = null,
    ) {
        $this->debit = Money::normalize($debit);
        $this->credit = Money::normalize($credit);

        $this->assertValid();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            accountId: (string) $payload['account_id'],
            debit: self::scalarAmount($payload['debit'] ?? '0'),
            credit: self::scalarAmount($payload['credit'] ?? '0'),
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            entityId: isset($payload['entity_id']) ? (string) $payload['entity_id'] : null,
        );
    }

    public function isDebit(): bool
    {
        return Money::isPositive($this->debit);
    }

    private function assertValid(): void
    {
        if (Money::isNegative($this->debit) || Money::isNegative($this->credit)) {
            throw new InvalidJournalLine('Debit dan kredit tidak boleh negatif.');
        }

        if (Money::isPositive($this->debit) && Money::isPositive($this->credit)) {
            throw new InvalidJournalLine(
                'Satu baris journal tidak boleh mengisi debit dan kredit sekaligus.'
            );
        }

        if (Money::isZero($this->debit) && Money::isZero($this->credit)) {
            throw new InvalidJournalLine('Baris journal harus memiliki nilai debit atau kredit.');
        }
    }

    /**
     * Nilai dari HTTP request bisa berupa int, float, atau string. Float ditolak oleh
     * Money::normalize(), jadi dikonversi lewat representasi string non-eksponensial
     * lebih dahulu agar pesan errornya tetap tentang nilai, bukan tipe.
     */
    private static function scalarAmount(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            return number_format($value, Money::SCALE, '.', '');
        }

        throw new InvalidJournalLine('Nilai debit/kredit harus berupa angka.');
    }
}

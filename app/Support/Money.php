<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmetika uang berbasis string/BCMath.
 *
 * plan.md §44.14: "jangan menggunakan floating point untuk uang". Seluruh nilai uang
 * di aplikasi ini direpresentasikan sebagai string desimal dengan skala tetap dan
 * dihitung memakai BCMath, sehingga tidak ada pembulatan biner yang bocor ke ledger.
 */
final class Money
{
    public const SCALE = 2;

    private function __construct() {}

    public static function zero(): string
    {
        return self::normalize('0');
    }

    /**
     * Normalisasi nilai apa pun (string/int) menjadi string desimal berskala tetap.
     *
     * Float sengaja ditolak agar kesalahan presisi tidak masuk lewat pintu belakang.
     */
    public static function normalize(int|string $value): string
    {
        $value = self::assertNumeric($value);

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(int|string $left, int|string $right): string
    {
        return bcadd(self::assertNumeric($left), self::assertNumeric($right), self::SCALE);
    }

    public static function subtract(int|string $left, int|string $right): string
    {
        return bcsub(self::assertNumeric($left), self::assertNumeric($right), self::SCALE);
    }

    public static function multiply(int|string $left, int|string $right): string
    {
        return bcmul(self::assertNumeric($left), self::assertNumeric($right), self::SCALE);
    }

    /**
     * @param  iterable<int|string>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = self::zero();

        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    public static function compare(int|string $left, int|string $right): int
    {
        return bccomp(self::assertNumeric($left), self::assertNumeric($right), self::SCALE);
    }

    public static function equals(int|string $left, int|string $right): bool
    {
        return self::compare($left, $right) === 0;
    }

    public static function isZero(int|string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isNegative(int|string $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function isPositive(int|string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function negate(int|string $value): string
    {
        return self::subtract('0', $value);
    }

    public static function abs(int|string $value): string
    {
        return self::isNegative($value) ? self::negate($value) : self::normalize($value);
    }

    private static function assertNumeric(int|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $value = trim($value);

        if ($value === '') {
            return '0';
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Nilai uang tidak valid: [{$value}].");
        }

        return $value;
    }
}

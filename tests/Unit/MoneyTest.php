<?php

declare(strict_types=1);

use App\Support\Money;

/*
 * plan.md §44.14: uang tidak boleh diwakili floating point. Test di bawah mengunci
 * perilaku aritmetika string/BCMath yang menggantikannya.
 */

it('menormalkan nilai ke dua desimal', function (): void {
    expect(Money::normalize('10'))->toBe('10.00');
    expect(Money::normalize(2500))->toBe('2500.00');
    expect(Money::normalize('0.1'))->toBe('0.10');
    expect(Money::normalize(''))->toBe('0.00');
});

it('memotong presisi di luar dua desimal tanpa pembulatan biner', function (): void {
    expect(Money::normalize('10.999'))->toBe('10.99');
    expect(Money::add('0.005', '0'))->toBe('0.00');
});

it('menolak float agar presisi tidak bocor', function (): void {
    // @phpstan-ignore-next-line argument.type
    expect(fn (): string => Money::normalize(0.1 + 0.2))
        ->toThrow(TypeError::class);
});

it('menolak string non-numerik', function (): void {
    expect(fn (): string => Money::normalize('1.000.000'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): string => Money::normalize('Rp 10000'))
        ->toThrow(InvalidArgumentException::class);
});

it('menjumlahkan tanpa galat presisi floating point', function (): void {
    $total = Money::zero();

    for ($i = 0; $i < 10; $i++) {
        $total = Money::add($total, '0.10');
    }

    expect($total)->toBe('1.00');
});

it('menjumlahkan nilai besar tanpa kehilangan sen', function (): void {
    expect(Money::sum(['99999999999.99', '0.01']))->toBe('100000000000.00');
});

it('menghitung selisih, perkalian, dan negasi', function (): void {
    expect(Money::subtract('100.00', '33.33'))->toBe('66.67');
    expect(Money::multiply('1500.50', '3'))->toBe('4501.50');
    expect(Money::negate('250.75'))->toBe('-250.75');
    expect(Money::abs('-250.75'))->toBe('250.75');
});

it('membandingkan nilai pada skala dua desimal', function (): void {
    expect(Money::equals('10', '10.00'))->toBeTrue();
    expect(Money::equals('10.001', '10.00'))->toBeTrue();
    expect(Money::equals('10.01', '10.00'))->toBeFalse();
    expect(Money::compare('9.99', '10.00'))->toBe(-1);
});

it('mengenali tanda nilai', function (): void {
    expect(Money::isZero('0.00'))->toBeTrue();
    expect(Money::isNegative('-0.01'))->toBeTrue();
    expect(Money::isPositive('0.01'))->toBeTrue();
    expect(Money::isPositive('0.00'))->toBeFalse();
});

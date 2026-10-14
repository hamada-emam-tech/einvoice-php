<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Tests\Unit;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function decimal_strings_become_exact_minor_units(): void
    {
        self::assertSame(11550, Money::of('115.5', 'SAR')->minor);
        self::assertSame(1234, Money::of('1.234', 'KWD')->minor);
        self::assertSame(500, Money::of('500', 'JPY')->minor);
        self::assertSame(-1, Money::of('-0.01', 'EGP')->minor);
        self::assertSame('115.50', Money::of('115.5', 'SAR')->toDecimal());
        self::assertSame('0.05', Money::minor(5, 'SAR')->toDecimal());
        self::assertSame('-1.234', Money::minor(-1234, 'KWD')->toDecimal());
    }

    #[Test]
    public function more_decimals_than_the_currency_allows_is_an_error_not_a_rounding(): void
    {
        $this->expectException(InvalidInvoice::class);
        Money::of('10.005', 'SAR');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function percentages(): iterable
    {
        yield '15% of 100' => ['100.00', '15', '15.00'];
        yield 'half rounds up' => ['0.10', '15', '0.02'];          // 1.5 halalas
        yield 'just under half rounds down' => ['0.03', '15', '0.00']; // 0.45 halalas
        yield 'negative half rounds away from zero' => ['-0.10', '15', '-0.02'];
        yield 'fractional rate' => ['200.00', '2.5', '5.00'];
    }

    #[Test]
    #[DataProvider('percentages')]
    public function percent_rounds_half_away_from_zero(string $amount, string $rate, string $expected): void
    {
        self::assertSame($expected, Money::of($amount, 'SAR')->percent($rate)->toDecimal());
    }

    #[Test]
    public function times_uses_exact_decimal_quantities(): void
    {
        self::assertSame('3.67', Money::of('1.10', 'SAR')->times('3.333')->toDecimal());
        self::assertSame('0.30', Money::of('0.10', 'SAR')->times('3')->toDecimal());   // 0.1 * 3 is not 0.30000000000000004 here
    }

    #[Test]
    public function currencies_cannot_be_mixed(): void
    {
        $this->expectException(InvalidInvoice::class);
        Money::of('1', 'SAR')->plus(Money::of('1', 'EGP'));
    }

    #[Test]
    public function floats_and_garbage_are_refused(): void
    {
        $this->expectException(InvalidInvoice::class);
        Money::of('1e3', 'SAR');
    }
}

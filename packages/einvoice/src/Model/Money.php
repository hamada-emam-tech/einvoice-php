<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;

/**
 * An amount in integer minor units (halalas, piasters, fils) plus its currency.
 * Never a float.
 *
 *     Money::of('115.50', 'SAR');   // 11550 halalas
 *     Money::minor(11550, 'SAR');   // the same
 */
final class Money
{
    /** Currencies whose minor unit is not 1/100. Everything else uses 2 decimals. */
    private const EXPONENTS = ['BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3, 'JPY' => 0, 'KRW' => 0];

    private function __construct(public readonly int $minor, public readonly string $currency)
    {
    }

    public static function minor(int $minor, string $currency): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidInvoice(sprintf('"%s" is not an ISO 4217 currency code.', $currency));
        }

        return new self($minor, $currency);
    }

    /** Parse a decimal string. More decimals than the currency allows is an error, not a silent rounding. */
    public static function of(string $amount, string $currency): self
    {
        $exponent = self::exponent($currency);
        [$scaled, $scale] = Decimal::parse($amount);
        if ($scale > $exponent) {
            throw new InvalidInvoice(sprintf('%s allows %d decimals, got "%s". Round it first.', $currency, $exponent, $amount));
        }

        return self::minor(Decimal::mulDiv($scaled, 10 ** ($exponent - $scale), 0), $currency);
    }

    public static function zero(string $currency): self
    {
        return self::minor(0, $currency);
    }

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[$currency] ?? 2;
    }

    /** "115.50" */
    public function toDecimal(): string
    {
        return Decimal::format($this->minor, self::exponent($this->currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiply by an exact decimal ("2.5", "0.15"), rounded half away from zero. */
    public function times(string $factor): self
    {
        [$scaled, $scale] = Decimal::parse($factor);

        return new self(Decimal::mulDiv($this->minor, $scaled, $scale), $this->currency);
    }

    /** $rate percent of this amount: Money::of('100.00', 'SAR')->percent('15') is 15.00 SAR. */
    public function percent(string $rate): self
    {
        [$scaled, $scale] = Decimal::parse($rate);

        return new self(Decimal::mulDiv($this->minor, $scaled, $scale + 2), $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidInvoice(sprintf('Cannot mix %s and %s.', $this->currency, $other->currency));
        }
    }
}

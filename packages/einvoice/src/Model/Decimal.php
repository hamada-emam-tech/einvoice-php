<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;

/**
 * Exact decimal arithmetic on integers. Amounts, quantities and rates travel as
 * strings ("2.500", "15.00") and are never turned into floats: a float changes
 * the canonical string, and one changed character breaks the signature.
 *
 * @internal
 */
final class Decimal
{
    /** @return array{0: int, 1: int} the value scaled to an integer, and the scale: "2.50" → [250, 2] */
    public static function parse(string $value): array
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidInvoice(sprintf('"%s" is not a decimal number. Pass amounts as strings like "12.50".', $value));
        }
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . $fraction, '0');
        if (strlen($digits) > 18) {
            throw new InvalidInvoice(sprintf('"%s" has too many digits.', $value));
        }
        $scaled = (int) ($digits === '' ? '0' : $digits);

        return [$m[1] === '-' ? -$scaled : $scaled, strlen($fraction)];
    }

    /** a × b ÷ 10^scale, rounded half away from zero. */
    public static function mulDiv(int $a, int $b, int $scale): int
    {
        $product = $a * $b;
        if (! is_int($product)) {
            throw new InvalidInvoice('Amount is too large.');
        }
        if ($scale === 0) {
            return $product;
        }
        $divisor = 10 ** $scale;
        $quotient = intdiv($product, $divisor);
        $remainder = abs($product % $divisor);
        if ($remainder * 2 >= $divisor) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return $quotient;
    }

    public static function format(int $scaled, int $scale): string
    {
        $sign = $scaled < 0 ? '-' : '';
        $digits = str_pad((string) abs($scaled), $scale + 1, '0', STR_PAD_LEFT);
        if ($scale === 0) {
            return $sign . $digits;
        }

        return $sign . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }
}

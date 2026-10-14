<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

/**
 * One tax applied to a line.
 *
 * $category is the authority's code (ZATCA: "S" standard, "Z" zero-rated, "E" exempt, "O" out of scope;
 * ETA: "T1" VAT, and so on). $exemptionReason is needed by most authorities when the rate is 0.
 */
final class Tax
{
    public function __construct(
        public readonly string $category,
        public readonly string $rate,
        public readonly Money $taxable,
        public readonly Money $amount,
        public readonly ?string $exemptionReason = null,
        public readonly ?string $exemptionCode = null,
    ) {
        Decimal::parse($rate);
    }

    /** Compute the amount from the rate: Tax::percent('S', '15', $net). */
    public static function percent(string $category, string $rate, Money $taxable, ?string $exemptionReason = null, ?string $exemptionCode = null): self
    {
        return new self($category, $rate, $taxable, $taxable->percent($rate), $exemptionReason, $exemptionCode);
    }
}

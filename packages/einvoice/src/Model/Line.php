<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

/**
 * One invoice line. $quantity is a decimal string ("2", "1.500").
 * The net amount is quantity × unit price − discount, rounded half away from zero.
 */
final class Line
{
    public readonly Money $net;

    /**
     * @param list<Tax>             $taxes
     * @param array<string, string> $extra authority-specific fields (item codes, unit types...)
     */
    public function __construct(
        public readonly string $description,
        public readonly string $quantity,
        public readonly Money $unitPrice,
        public readonly array $taxes = [],
        public readonly ?Money $discount = null,
        public readonly ?string $itemCode = null,
        public readonly array $extra = [],
    ) {
        $this->net = self::netOf($quantity, $unitPrice, $discount);
    }

    /** A line with one percentage tax on its net amount, the common case. */
    public static function taxed(string $description, string $quantity, Money $unitPrice, string $taxCategory, string $taxRate, ?Money $discount = null): self
    {
        $tax = Tax::percent($taxCategory, $taxRate, self::netOf($quantity, $unitPrice, $discount));

        return new self($description, $quantity, $unitPrice, [$tax], $discount);
    }

    public function taxTotal(): Money
    {
        $total = Money::zero($this->unitPrice->currency);
        foreach ($this->taxes as $tax) {
            $total = $total->plus($tax->amount);
        }

        return $total;
    }

    public function gross(): Money
    {
        return $this->net->plus($this->taxTotal());
    }

    private static function netOf(string $quantity, Money $unitPrice, ?Money $discount): Money
    {
        $net = $unitPrice->times($quantity);

        return $discount === null ? $net : $net->minus($discount);
    }
}

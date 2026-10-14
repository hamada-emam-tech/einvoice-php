<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

use DateTimeImmutable;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;

/**
 * An invoice, credit note or debit note, independent of any authority.
 *
 * Each authority package turns it into its own format (UBL XML for ZATCA, JSON for ETA).
 * Anything only one authority needs goes in $extra, keyed the way that authority's README says.
 * $buyer is null for a simplified (B2C) invoice where the authority allows it.
 */
final class Invoice
{
    /**
     * @param list<Line>           $lines
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $number,
        public readonly DateTimeImmutable $issuedAt,
        public readonly Party $seller,
        public readonly ?Party $buyer,
        public readonly array $lines,
        public readonly string $currency,
        public readonly InvoiceType $type = InvoiceType::Invoice,
        public readonly ?string $uuid = null,
        public readonly ?string $referencedInvoice = null,
        public readonly array $extra = [],
    ) {
        if ($lines === []) {
            throw new InvalidInvoice("Invoice {$number} has no lines.");
        }
        foreach ($lines as $line) {
            if ($line->unitPrice->currency !== $currency) {
                throw new InvalidInvoice("Invoice {$number} is in {$currency} but line \"{$line->description}\" is in {$line->unitPrice->currency}.");
            }
        }
        if ($type !== InvoiceType::Invoice && $referencedInvoice === null) {
            throw new InvalidInvoice("A {$type->value} must reference the original invoice.");
        }
    }

    public function netTotal(): Money
    {
        return $this->sum(fn (Line $line) => $line->net);
    }

    public function taxTotal(): Money
    {
        return $this->sum(fn (Line $line) => $line->taxTotal());
    }

    public function grossTotal(): Money
    {
        return $this->netTotal()->plus($this->taxTotal());
    }

    /** @param callable(Line): Money $amount */
    private function sum(callable $amount): Money
    {
        $total = Money::zero($this->currency);
        foreach ($this->lines as $line) {
            $total = $total->plus($amount($line));
        }

        return $total;
    }
}

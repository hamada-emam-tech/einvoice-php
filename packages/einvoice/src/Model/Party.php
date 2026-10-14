<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

/**
 * A seller or a buyer.
 *
 * $taxNumber is the VAT / tax registration number. $identifiers holds the other
 * IDs an authority may ask for, keyed by the authority's scheme code:
 * ['CRN' => '1010010000'] for a Saudi commercial registration, for example.
 */
final class Party
{
    /** @param array<string, string> $identifiers */
    public function __construct(
        public readonly string $name,
        public readonly ?string $taxNumber = null,
        public readonly ?Address $address = null,
        public readonly array $identifiers = [],
    ) {
    }
}

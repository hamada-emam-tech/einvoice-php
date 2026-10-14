<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Contracts;

/**
 * Every authority package ships one factory and declares it in its composer.json:
 *
 *     "extra": { "einvoice": { "authorities": { "zatca": "Vendor\\Zatca\\ZatcaFactory" } } }
 *
 * The core discovers it from Composer's installed packages, so installing the
 * package is enough to make the authority available.
 */
interface AuthorityFactory
{
    /** @param array<string, mixed> $config the authority's block from the Einvoice config, without "key" */
    public function create(array $config): TaxAuthority;
}

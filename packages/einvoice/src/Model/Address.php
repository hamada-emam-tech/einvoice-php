<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Model;

/** A postal address. Some authorities require fields others ignore (ZATCA: building number, district). */
final class Address
{
    public function __construct(
        public readonly string $countryCode,
        public readonly string $city,
        public readonly ?string $street = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $district = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $region = null,
        public readonly ?string $additionalNumber = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Onboarding;

use HamadaEmamTech\Einvoice\Exceptions\EinvoiceException;

/**
 * What ZATCA wants to know about one EGS unit (one POS, one ERP instance) in its CSR.
 *
 *     new CsrDetails(
 *         commonName: 'POS-1',                    // your own ID for this unit
 *         serialNumber: '1-MyERP|2-4.2|3-' . $uuid, // 1-solution name | 2-model or version | 3-unit serial
 *         organizationIdentifier: '399999999900003',// VAT number: 15 digits, starts and ends with 3
 *         organizationUnit: 'Riyadh Branch',      // for a VAT group: the member's 10-digit TIN
 *         organizationName: 'Example Trading Co',
 *         invoiceTypes: '1100',                   // standard | simplified | reserved | reserved
 *         location: 'RRRD2929',                   // branch address (short national address works)
 *         industry: 'Retail',
 *     );
 */
final class CsrDetails
{
    public function __construct(
        public readonly string $commonName,
        public readonly string $serialNumber,
        public readonly string $organizationIdentifier,
        public readonly string $organizationUnit,
        public readonly string $organizationName,
        public readonly string $invoiceTypes,
        public readonly string $location,
        public readonly string $industry,
        public readonly string $countryCode = 'SA',
    ) {
        if (preg_match('/^3\d{13}3$/', $organizationIdentifier) !== 1) {
            throw new EinvoiceException('The organization identifier must be the 15-digit VAT number, starting and ending with 3.');
        }
        if (preg_match('/^[01]{4}$/', $invoiceTypes) !== 1 || $invoiceTypes[0] . $invoiceTypes[1] === '00') {
            throw new EinvoiceException('invoiceTypes is 4 digits of 0/1: standard, simplified, then two reserved digits. Example: "1100".');
        }
        if (preg_match('/^1-[^|]+\|2-[^|]+\|3-[^|]+$/', $serialNumber) !== 1) {
            throw new EinvoiceException('serialNumber must look like "1-<solution>|2-<model or version>|3-<unit serial>".');
        }
    }
}

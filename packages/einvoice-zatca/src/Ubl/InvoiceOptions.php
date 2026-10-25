<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Ubl;

use DateTimeImmutable;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;

/**
 * The ZATCA-only values of an invoice, read from $invoice->extra['zatca']:
 *
 *     'zatca' => [
 *         'icv'           => 42,            // required: this EGS unit's invoice counter, +1 per signed document
 *         'previous_hash' => $lastHash,     // the hash of the previous signed document; omit for the very first one
 *         'simplified'    => true,          // B2C. Default: true when the buyer has no VAT number
 *         'supply_date'   => '2026-10-25',  // standard invoices; default: the issue date
 *         'payment_means' => '10',          // UN/ECE 4461: 10 cash, 30 credit transfer, 42 bank account, 48 card, 1 not defined
 *         'reason'        => 'Returned',    // required on credit and debit notes
 *     ]
 *
 * @internal
 */
final class InvoiceOptions
{
    /** PIH of the first invoice of an EGS unit: base64 of the hex SHA-256 of "0". */
    public const FIRST_PREVIOUS_HASH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    private function __construct(
        public readonly int $counter,
        public readonly string $previousHash,
        public readonly bool $simplified,
        public readonly DateTimeImmutable $supplyDate,
        public readonly string $paymentMeans,
        public readonly ?string $reason,
    ) {
    }

    public static function of(Invoice $invoice): self
    {
        $zatca = $invoice->extra['zatca'] ?? [];
        if (! is_array($zatca)) {
            throw new InvalidInvoice('extra.zatca must be an array.');
        }

        $counter = $zatca['icv'] ?? null;
        if (! is_int($counter) || $counter < 1) {
            throw new InvalidInvoice("Invoice {$invoice->number}: extra.zatca.icv (the invoice counter, a positive integer) is required.");
        }

        $reason = isset($zatca['reason']) ? (string) $zatca['reason'] : null;
        if ($invoice->type !== InvoiceType::Invoice && ($reason === null || trim($reason) === '')) {
            throw new InvalidInvoice("Invoice {$invoice->number}: a credit or debit note needs extra.zatca.reason.");
        }

        $supply = $zatca['supply_date'] ?? null;

        return new self(
            $counter,
            (string) ($zatca['previous_hash'] ?? self::FIRST_PREVIOUS_HASH),
            (bool) ($zatca['simplified'] ?? ($invoice->buyer?->taxNumber === null)),
            is_string($supply) ? new DateTimeImmutable($supply) : $invoice->issuedAt,
            (string) ($zatca['payment_means'] ?? '10'),
            $reason,
        );
    }
}

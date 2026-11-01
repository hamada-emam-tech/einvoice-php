<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta;

use DateTimeZone;
use HamadaEmamTech\Einvoice\Eta\Json\Number;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Address;
use HamadaEmamTech\Einvoice\Model\Decimal;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Party;

/**
 * Turns an Invoice into an ETA Invoice / Credit Note / Debit Note v1.0 document
 * (sdk.invoicing.eta.gov.eg/documents/invoice-v1-0).
 *
 * Amounts are computed here, exactly, at ETA's 5 decimals: sales total = quantity × unit price,
 * net = sales − discount, tax = net × rate. Tax::$amount is not used, so the document always
 * matches ETA's own arithmetic.
 *
 * Covered: EGP, VAT (T1) with its subtype in Tax::$exemptionCode ("V009"...). For anything
 * else (foreign currency, table tax, fees...) set the fields in $invoice->extra['eta'], or build
 * the document yourself and use EtaAuthority::prepareDocument().
 *
 * @internal
 */
final class DocumentBuilder
{
    private const SCALE = 5;

    public function __construct(
        private readonly string $activityCode,
        private readonly string $version = '1.0',
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Invoice $invoice): array
    {
        if ($invoice->currency !== 'EGP') {
            throw new InvalidInvoice("Invoice {$invoice->number} is in {$invoice->currency}. The ETA builder handles EGP only; for a foreign currency set unitValue per line through extra['eta'] or use prepareDocument().");
        }

        $lines = [];
        $sales = $discounts = $nets = $taxes = 0;
        $taxTotals = [];
        foreach ($invoice->lines as $line) {
            [$document, $amounts] = $this->line($invoice, $line);
            $lines[] = $document;
            $sales += $amounts['sales'];
            $discounts += $amounts['discount'];
            $nets += $amounts['net'];
            foreach ($amounts['taxes'] as $type => $amount) {
                $taxTotals[$type] = ($taxTotals[$type] ?? 0) + $amount;
                $taxes += $amount;
            }
        }

        $document = [
            'issuer' => $this->issuer($invoice->seller),
            'receiver' => $this->receiver($invoice->buyer),
            'documentType' => match ($invoice->type) {
                InvoiceType::Invoice => 'I',
                InvoiceType::CreditNote => 'C',
                InvoiceType::DebitNote => 'D',
            },
            'documentTypeVersion' => $this->version,
            'dateTimeIssued' => $invoice->issuedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'taxpayerActivityCode' => (string) ($invoice->extra['activity_code'] ?? $this->activityCode),
            'internalID' => $invoice->number,
        ];
        if ($invoice->referencedInvoice !== null) {
            $document['references'] = [$invoice->referencedInvoice];
        }
        $document += [
            'invoiceLines' => $lines,
            'totalDiscountAmount' => self::number($discounts),
            'totalSalesAmount' => self::number($sales),
            'netAmount' => self::number($nets),
            'taxTotals' => array_map(fn (string $type, int $amount) => ['taxType' => $type, 'amount' => self::number($amount)], array_keys($taxTotals), $taxTotals),
            'totalAmount' => self::number($nets + $taxes),
            'extraDiscountAmount' => self::number(0),
            'totalItemsDiscountAmount' => self::number(0),
        ];

        $overrides = $invoice->extra['eta'] ?? [];
        if (! is_array($overrides)) {
            throw new InvalidInvoice("Invoice {$invoice->number}: extra['eta'] must be an array of ETA document fields.");
        }

        return array_replace($document, $overrides);
    }

    /** @return array{array<string, mixed>, array{sales: int, discount: int, net: int, taxes: array<string, int>}} */
    private function line(Invoice $invoice, Line $line): array
    {
        $itemCode = $line->itemCode ?? throw new InvalidInvoice("Invoice {$invoice->number}, line \"{$line->description}\": ETA needs the item code (GS1 or EGS) in Line::\$itemCode.");
        [$quantity, $quantityScale] = Decimal::parse($line->quantity);
        if ($quantity <= 0) {
            throw new InvalidInvoice("Invoice {$invoice->number}, line \"{$line->description}\": ETA needs a quantity larger than 0.");
        }

        // Unit price in minor units (2 decimals) × quantity, brought to 5 decimals.
        $sales = self::rescale(Decimal::mulDiv($quantity, $line->unitPrice->minor, 0), $quantityScale + 2);
        $discount = self::rescale($line->discount->minor ?? 0, 2);
        $net = $sales - $discount;

        $taxableItems = $taxes = [];
        foreach ($line->taxes as $tax) {
            if ($tax->category !== 'T1') {
                throw new InvalidInvoice("Invoice {$invoice->number}, line \"{$line->description}\": the ETA builder handles VAT (T1) only, got \"{$tax->category}\". Set taxableItems through extra['eta'] or use prepareDocument().");
            }
            if (isset($taxes['T1'])) {
                throw new InvalidInvoice("Invoice {$invoice->number}, line \"{$line->description}\": ETA allows each tax type once per line.");
            }
            [$rate, $rateScale] = Decimal::parse($tax->rate);
            $taxes['T1'] = Decimal::mulDiv($net, $rate, $rateScale + 2);
            $taxableItems[] = [
                'taxType' => 'T1',
                'amount' => self::number($taxes['T1']),
                'subType' => $tax->exemptionCode ?? throw new InvalidInvoice("Invoice {$invoice->number}, line \"{$line->description}\": ETA needs the T1 subtype (\"V009\" for standard VAT...) in Tax::\$exemptionCode."),
                'rate' => Number::of($tax->rate),
            ];
        }

        $document = [
            'description' => $line->description,
            'itemType' => $line->extra['item_type'] ?? 'EGS',
            'itemCode' => $itemCode,
            'unitType' => $line->extra['unit_type'] ?? 'EA',
            'quantity' => Number::of($line->quantity),
        ];
        if (isset($line->extra['internal_code'])) {
            $document['internalCode'] = $line->extra['internal_code'];
        }
        $document += [
            'salesTotal' => self::number($sales),
            'total' => self::number($net + array_sum($taxes)),
            'valueDifference' => self::number(0),
            'totalTaxableFees' => self::number(0),
            'netTotal' => self::number($net),
            'itemsDiscount' => self::number(0),
            'unitValue' => ['currencySold' => 'EGP', 'amountEGP' => Number::of($line->unitPrice->toDecimal())],
            // ETA's own samples send rate 0 with the amount when the discount is a fixed amount.
            'discount' => ['rate' => Number::of('0'), 'amount' => self::number($discount)],
            'taxableItems' => $taxableItems,
        ];

        return [$document, ['sales' => $sales, 'discount' => $discount, 'net' => $net, 'taxes' => $taxes]];
    }

    /** @return array<string, mixed> */
    private function issuer(Party $seller): array
    {
        return [
            'address' => ['branchID' => $seller->identifiers['branch_id'] ?? '0'] + $this->address($seller->address, 'seller'),
            'type' => $seller->identifiers['type'] ?? 'B',
            'id' => $seller->taxNumber ?? throw new InvalidInvoice('ETA needs the seller registration number in Party::$taxNumber.'),
            'name' => $seller->name,
        ];
    }

    /**
     * B for a business in Egypt (has a tax number), F for a buyer abroad, P for a person.
     * A person's national ID goes in $identifiers['national_id']. Set $identifiers['type'] to choose.
     *
     * @return array<string, mixed>
     */
    private function receiver(?Party $buyer): array
    {
        if ($buyer === null) {
            return ['type' => 'P'];
        }
        $type = $buyer->identifiers['type'] ?? match (true) {
            $buyer->address !== null && $buyer->address->countryCode !== 'EG' => 'F',
            $buyer->taxNumber !== null => 'B',
            default => 'P',
        };
        $receiver = $buyer->address === null ? [] : ['address' => $this->address($buyer->address, 'buyer')];
        $receiver['type'] = $type;
        $id = $type === 'P' ? ($buyer->identifiers['national_id'] ?? null) : $buyer->taxNumber;
        if ($id !== null) {
            $receiver['id'] = $id;
        }
        $receiver['name'] = $buyer->name;

        return $receiver;
    }

    /** @return array<string, string> */
    private function address(?Address $address, string $who): array
    {
        if ($address === null) {
            throw new InvalidInvoice("ETA needs the {$who} address.");
        }
        $missing = static fn (string $field) => throw new InvalidInvoice("ETA needs the {$who} address {$field}.");
        $fields = [
            'country' => $address->countryCode,
            'governate' => $address->region ?? $missing('region (governorate)'),
            'regionCity' => $address->city,
            'street' => $address->street ?? $missing('street'),
            'buildingNumber' => $address->buildingNumber ?? $missing('building number'),
        ];
        if ($address->postalCode !== null) {
            $fields['postalCode'] = $address->postalCode;
        }

        return $fields;
    }

    /** A value at $scale decimals, brought to 5 decimals (rounded half away from zero if it has more). */
    private static function rescale(int $value, int $scale): int
    {
        return $scale <= self::SCALE
            ? $value * 10 ** (self::SCALE - $scale)
            : Decimal::mulDiv($value, 1, $scale - self::SCALE);
    }

    private static function number(int $scaled): Number
    {
        return Number::of(Decimal::format($scaled, self::SCALE));
    }
}

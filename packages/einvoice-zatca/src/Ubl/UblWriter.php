<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Ubl;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Address;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Money;
use HamadaEmamTech\Einvoice\Model\Party;
use HamadaEmamTech\Einvoice\Model\Tax;
use XMLWriter;

/**
 * Turns the core Invoice into ZATCA's UBL 2.1 XML, without the signature parts
 * (ext:UBLExtensions and the QR), which ZatcaAuthority::sign() adds.
 *
 * No whitespace between elements: nothing for the hash to disagree about.
 *
 * @internal
 */
final class UblWriter
{
    public const NS = [
        '' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
    ];

    private const TYPE_CODES = [InvoiceType::Invoice->value => '388', InvoiceType::CreditNote->value => '381', InvoiceType::DebitNote->value => '383'];

    private XMLWriter $xml;

    public function write(Invoice $invoice, InvoiceOptions $options, string $uuid): string
    {
        if ($invoice->currency !== 'SAR') {
            throw new InvalidInvoice("Invoice {$invoice->number}: this version supports SAR invoices only (ZATCA needs the VAT total in SAR).");
        }

        $this->xml = new XMLWriter();
        $this->xml->openMemory();
        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->startElement('Invoice');
        foreach (self::NS as $prefix => $uri) {
            $this->xml->writeAttribute($prefix === '' ? 'xmlns' : "xmlns:{$prefix}", $uri);
        }

        $this->text('cbc:ProfileID', 'reporting:1.0');
        $this->text('cbc:ID', $invoice->number);
        $this->text('cbc:UUID', $uuid);
        $this->text('cbc:IssueDate', $invoice->issuedAt->format('Y-m-d'));
        $this->text('cbc:IssueTime', $invoice->issuedAt->format('H:i:s'));
        $this->text('cbc:InvoiceTypeCode', self::TYPE_CODES[$invoice->type->value], ['name' => $options->simplified ? '0200000' : '0100000']);
        $this->text('cbc:DocumentCurrencyCode', $invoice->currency);
        $this->text('cbc:TaxCurrencyCode', 'SAR');

        if ($invoice->referencedInvoice !== null) {
            $this->xml->startElement('cac:BillingReference');
            $this->xml->startElement('cac:InvoiceDocumentReference');
            $this->text('cbc:ID', $invoice->referencedInvoice);
            $this->xml->endElement();
            $this->xml->endElement();
        }

        $this->xml->startElement('cac:AdditionalDocumentReference');
        $this->text('cbc:ID', 'ICV');
        $this->text('cbc:UUID', (string) $options->counter);
        $this->xml->endElement();
        $this->xml->startElement('cac:AdditionalDocumentReference');
        $this->text('cbc:ID', 'PIH');
        $this->xml->startElement('cac:Attachment');
        $this->text('cbc:EmbeddedDocumentBinaryObject', $options->previousHash, ['mimeCode' => 'text/plain']);
        $this->xml->endElement();
        $this->xml->endElement();

        $this->xml->startElement('cac:Signature');
        $this->text('cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $this->text('cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $this->xml->endElement();

        $this->xml->startElement('cac:AccountingSupplierParty');
        $this->party($invoice->seller, true);
        $this->xml->endElement();
        $this->xml->startElement('cac:AccountingCustomerParty');
        if ($invoice->buyer !== null) {
            $this->party($invoice->buyer, false);
        } elseif (! $options->simplified) {
            throw new InvalidInvoice("Invoice {$invoice->number}: a standard (B2B) invoice needs a buyer.");
        }
        $this->xml->endElement();

        if (! $options->simplified) {
            $this->xml->startElement('cac:Delivery');
            $this->text('cbc:ActualDeliveryDate', $options->supplyDate->format('Y-m-d'));
            $this->xml->endElement();
        }

        $this->xml->startElement('cac:PaymentMeans');
        $this->text('cbc:PaymentMeansCode', $options->paymentMeans);
        if ($options->reason !== null) {
            $this->text('cbc:InstructionNote', $options->reason);
        }
        $this->xml->endElement();

        $this->taxTotals($invoice);

        $net = $invoice->netTotal();
        $this->xml->startElement('cac:LegalMonetaryTotal');
        $this->amount('cbc:LineExtensionAmount', $net);
        $this->amount('cbc:TaxExclusiveAmount', $net);
        $this->amount('cbc:TaxInclusiveAmount', $invoice->grossTotal());
        $this->amount('cbc:AllowanceTotalAmount', Money::zero($invoice->currency));
        $this->amount('cbc:PrepaidAmount', Money::zero($invoice->currency));
        $this->amount('cbc:PayableAmount', $invoice->grossTotal());
        $this->xml->endElement();

        foreach ($invoice->lines as $i => $line) {
            $this->line($i + 1, $line, $invoice->number);
        }

        $this->xml->endElement();
        $this->xml->endDocument();

        return $this->xml->outputMemory();
    }

    private function party(Party $party, bool $seller): void
    {
        $this->xml->startElement('cac:Party');
        foreach ($party->identifiers as $scheme => $id) {
            $this->xml->startElement('cac:PartyIdentification');
            $this->text('cbc:ID', $id, ['schemeID' => $scheme]);
            $this->xml->endElement();
        }
        if ($party->address !== null) {
            $this->address($party->address);
        } elseif ($seller) {
            throw new InvalidInvoice('The seller needs an address.');
        }
        $this->xml->startElement('cac:PartyTaxScheme');
        if ($party->taxNumber !== null) {
            $this->text('cbc:CompanyID', $party->taxNumber);
        } elseif ($seller) {
            throw new InvalidInvoice('The seller needs its VAT number.');
        }
        $this->xml->startElement('cac:TaxScheme');
        $this->text('cbc:ID', 'VAT');
        $this->xml->endElement();
        $this->xml->endElement();
        $this->xml->startElement('cac:PartyLegalEntity');
        $this->text('cbc:RegistrationName', $party->name);
        $this->xml->endElement();
        $this->xml->endElement();
    }

    private function address(Address $address): void
    {
        $this->xml->startElement('cac:PostalAddress');
        $this->optional('cbc:StreetName', $address->street);
        $this->optional('cbc:BuildingNumber', $address->buildingNumber);
        $this->optional('cbc:PlotIdentification', $address->additionalNumber);
        $this->optional('cbc:CitySubdivisionName', $address->district);
        $this->text('cbc:CityName', $address->city);
        $this->optional('cbc:PostalZone', $address->postalCode);
        $this->optional('cbc:CountrySubentity', $address->region);
        $this->xml->startElement('cac:Country');
        $this->text('cbc:IdentificationCode', $address->countryCode);
        $this->xml->endElement();
        $this->xml->endElement();
    }

    private function taxTotals(Invoice $invoice): void
    {
        /** @var array<string, array{Tax, Money, Money}> $groups same category, rate and exemption: taxable, tax */
        $groups = [];
        foreach ($invoice->lines as $line) {
            $tax = self::vat($line, $invoice->number);
            $key = "{$tax->category}|{$tax->rate}|{$tax->exemptionCode}";
            [, $taxable, $amount] = $groups[$key] ?? [$tax, Money::zero($invoice->currency), Money::zero($invoice->currency)];
            $groups[$key] = [$tax, $taxable->plus($tax->taxable), $amount->plus($tax->amount)];
        }

        $this->xml->startElement('cac:TaxTotal');
        $this->amount('cbc:TaxAmount', $invoice->taxTotal());
        $this->xml->endElement();

        $this->xml->startElement('cac:TaxTotal');
        $this->amount('cbc:TaxAmount', $invoice->taxTotal());
        foreach ($groups as [$tax, $taxable, $amount]) {
            $this->xml->startElement('cac:TaxSubtotal');
            $this->amount('cbc:TaxableAmount', $taxable);
            $this->amount('cbc:TaxAmount', $amount);
            $this->xml->startElement('cac:TaxCategory');
            $this->text('cbc:ID', $tax->category, ['schemeID' => 'UN/ECE 5305', 'schemeAgencyID' => '6']);
            $this->text('cbc:Percent', self::percent($tax->rate));
            $this->optional('cbc:TaxExemptionReasonCode', $tax->exemptionCode);
            $this->optional('cbc:TaxExemptionReason', $tax->exemptionReason);
            $this->xml->startElement('cac:TaxScheme');
            $this->text('cbc:ID', 'VAT', ['schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']);
            $this->xml->endElement();
            $this->xml->endElement();
            $this->xml->endElement();
        }
        $this->xml->endElement();
    }

    private function line(int $number, Line $line, string $invoiceNumber): void
    {
        $tax = self::vat($line, $invoiceNumber);

        $this->xml->startElement('cac:InvoiceLine');
        $this->text('cbc:ID', (string) $number);
        $this->text('cbc:InvoicedQuantity', $line->quantity, ['unitCode' => $line->extra['unit_code'] ?? 'PCE']);
        $this->amount('cbc:LineExtensionAmount', $line->net);
        if ($line->discount !== null && ! $line->discount->isZero()) {
            $this->xml->startElement('cac:AllowanceCharge');
            $this->text('cbc:ChargeIndicator', 'false');
            $this->text('cbc:AllowanceChargeReason', $line->extra['discount_reason'] ?? 'Discount');
            $this->amount('cbc:Amount', $line->discount);
            $this->xml->endElement();
        }
        $this->xml->startElement('cac:TaxTotal');
        $this->amount('cbc:TaxAmount', $tax->amount);
        $this->amount('cbc:RoundingAmount', $line->gross());
        $this->xml->endElement();
        $this->xml->startElement('cac:Item');
        $this->text('cbc:Name', $line->description);
        if ($line->itemCode !== null) {
            $this->xml->startElement('cac:SellersItemIdentification');
            $this->text('cbc:ID', $line->itemCode);
            $this->xml->endElement();
        }
        $this->xml->startElement('cac:ClassifiedTaxCategory');
        $this->text('cbc:ID', $tax->category);
        $this->text('cbc:Percent', self::percent($tax->rate));
        $this->xml->startElement('cac:TaxScheme');
        $this->text('cbc:ID', 'VAT');
        $this->xml->endElement();
        $this->xml->endElement();
        $this->xml->endElement();
        $this->xml->startElement('cac:Price');
        $this->amount('cbc:PriceAmount', $line->unitPrice);
        $this->xml->endElement();
        $this->xml->endElement();
    }

    /** ZATCA lines carry exactly one VAT category. */
    private static function vat(Line $line, string $invoiceNumber): Tax
    {
        if (count($line->taxes) !== 1) {
            throw new InvalidInvoice("Invoice {$invoiceNumber}, line \"{$line->description}\": ZATCA needs exactly one VAT tax per line.");
        }
        $tax = $line->taxes[0];
        if (! in_array($tax->category, ['S', 'Z', 'E', 'O'], true)) {
            throw new InvalidInvoice("Invoice {$invoiceNumber}: VAT category must be S, Z, E or O, got \"{$tax->category}\".");
        }
        if ($tax->category !== 'S' && ($tax->exemptionCode === null || $tax->exemptionReason === null)) {
            throw new InvalidInvoice("Invoice {$invoiceNumber}: VAT category {$tax->category} needs an exemption code (VATEX-SA-...) and reason.");
        }

        return $tax;
    }

    private static function percent(string $rate): string
    {
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return $whole . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function amount(string $name, Money $money): void
    {
        $this->text($name, $money->toDecimal(), ['currencyID' => $money->currency]);
    }

    private function optional(string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $this->text($name, $value);
        }
    }

    /** @param array<string, string> $attributes */
    private function text(string $name, string $value, array $attributes = []): void
    {
        $this->xml->startElement($name);
        foreach ($attributes as $attribute => $attributeValue) {
            $this->xml->writeAttribute($attribute, $attributeValue);
        }
        $this->xml->text($value);
        $this->xml->endElement();
    }
}

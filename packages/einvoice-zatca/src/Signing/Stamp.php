<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Signing;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use HamadaEmamTech\Einvoice\Zatca\Ubl\UblWriter;

/**
 * ZATCA's cryptographic stamp: the invoice hash, the ECDSA signature over it, the XAdES
 * signed properties, and the QR code. ZATCA's rules differ from plain XAdES in three places,
 * all handled here:
 *
 *  - the signature is over the invoice hash bytes, not over a canonical SignedInfo;
 *  - the certificate digest and the signed-properties digest are base64 of the *hex* SHA-256;
 *  - the hash covers the invoice without ext:UBLExtensions, cac:Signature and the QR reference.
 *
 * @internal
 */
final class Stamp
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /** base64 of the raw SHA-256 of the canonical invoice, without its signature parts. */
    public static function invoiceHash(string $xml): string
    {
        $document = self::load($xml);
        $xpath = self::xpath($document);
        foreach (['/i:Invoice/ext:UBLExtensions', '/i:Invoice/cac:Signature', "/i:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']"] as $query) {
            foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $canonical = $document->documentElement?->C14N(false, false);
        if (! is_string($canonical)) {
            throw new SigningError('Could not canonicalize the invoice.');
        }

        return base64_encode(hash('sha256', $canonical, true));
    }

    /**
     * @param string $xml the prepared invoice (UblWriter output)
     *
     * @return array{xml: string, hash: string, qr: string, signature: string}
     */
    public static function apply(string $xml, KeySource $key, Certificate $certificate, bool $simplified, ?DateTimeImmutable $signingTime = null): array
    {
        if (! $key->supports(SignatureFormat::RawEcdsa)) {
            throw SigningError::unsupported($key->name(), SignatureFormat::RawEcdsa);
        }

        $hash = self::invoiceHash($xml);
        $signature = base64_encode($key->sign((string) base64_decode($hash), SignatureFormat::RawEcdsa));

        $time = ($signingTime ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $properties = self::signedProperties($time, $certificate, true);
        $propertiesHash = base64_encode(hash('sha256', $properties));

        $qr = self::qr($xml, $hash, $signature, $certificate, $simplified);

        $extensions = '<ext:UBLExtensions><ext:UBLExtension>'
            . '<ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>'
            . '<ext:ExtensionContent>'
            . '<sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2"'
            . ' xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2"'
            . ' xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">'
            . '<sac:SignatureInformation>'
            . '<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>'
            . '<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'
            . '<ds:Signature xmlns:ds="' . self::DS . '" Id="signature">'
            . '<ds:SignedInfo>'
            . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
            . '<ds:Reference Id="invoiceSignedData" URI="">'
            . '<ds:Transforms>'
            . '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath></ds:Transform>'
            . '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath></ds:Transform>'
            . '<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath></ds:Transform>'
            . '<ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
            . '</ds:Transforms>'
            . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<ds:DigestValue>' . $hash . '</ds:DigestValue>'
            . '</ds:Reference>'
            . '<ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">'
            . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<ds:DigestValue>' . $propertiesHash . '</ds:DigestValue>'
            . '</ds:Reference>'
            . '</ds:SignedInfo>'
            . '<ds:SignatureValue>' . $signature . '</ds:SignatureValue>'
            . '<ds:KeyInfo><ds:X509Data><ds:X509Certificate>' . $certificate->body . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
            . '<ds:Object><xades:QualifyingProperties xmlns:xades="' . self::XADES . '" Target="signature">'
            . self::signedProperties($time, $certificate, false)
            . '</xades:QualifyingProperties></ds:Object>'
            . '</ds:Signature>'
            . '</sac:SignatureInformation>'
            . '</sig:UBLDocumentSignatures>'
            . '</ext:ExtensionContent>'
            . '</ext:UBLExtension></ext:UBLExtensions>';

        $qrReference = '<cac:AdditionalDocumentReference><cbc:ID>QR</cbc:ID><cac:Attachment>'
            . '<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">' . $qr . '</cbc:EmbeddedDocumentBinaryObject>'
            . '</cac:Attachment></cac:AdditionalDocumentReference>';

        $root = strpos($xml, '>', (int) strpos($xml, '<Invoice')) + 1;
        $pih = strpos($xml, '</cac:AdditionalDocumentReference>', (int) strpos($xml, '<cbc:ID>PIH</cbc:ID>')) + strlen('</cac:AdditionalDocumentReference>');
        if ($root === 1 || $pih <= $root) {
            throw new SigningError('The document is not a prepared ZATCA invoice.');
        }
        $signed = substr($xml, 0, $root) . $extensions . substr($xml, $root, $pih - $root) . $qrReference . substr($xml, $pih);

        return ['xml' => $signed, 'hash' => $hash, 'qr' => $qr, 'signature' => $signature];
    }

    /**
     * The xades:SignedProperties element. ZATCA hashes it with the ds namespace declared on each
     * ds element ($forHashing); inside the document the namespace comes from ds:Signature.
     * DigestMethod must be self-closing: the reporting endpoint rejects the hash of the expanded form.
     */
    private static function signedProperties(string $time, Certificate $certificate, bool $forHashing): string
    {
        $ds = $forHashing ? ' xmlns:ds="' . self::DS . '"' : '';

        return '<xades:SignedProperties xmlns:xades="' . self::XADES . '" Id="xadesSignedProperties">'
            . '<xades:SignedSignatureProperties>'
            . '<xades:SigningTime>' . $time . '</xades:SigningTime>'
            . '<xades:SigningCertificate><xades:Cert>'
            . '<xades:CertDigest>'
            . '<ds:DigestMethod' . $ds . ' Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<ds:DigestValue' . $ds . '>' . $certificate->hash() . '</ds:DigestValue>'
            . '</xades:CertDigest>'
            . '<xades:IssuerSerial>'
            . '<ds:X509IssuerName' . $ds . '>' . htmlspecialchars($certificate->issuer(), ENT_XML1) . '</ds:X509IssuerName>'
            . '<ds:X509SerialNumber' . $ds . '>' . $certificate->serialNumber() . '</ds:X509SerialNumber>'
            . '</xades:IssuerSerial>'
            . '</xades:Cert></xades:SigningCertificate>'
            . '</xades:SignedSignatureProperties>'
            . '</xades:SignedProperties>';
    }

    private static function qr(string $xml, string $hash, string $signature, Certificate $certificate, bool $simplified): string
    {
        $xpath = self::xpath(self::load($xml));
        $value = static fn (string $query): string => trim((string) $xpath->evaluate("string({$query})"));

        $tags = [
            1 => $value('/i:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'),
            2 => $value('/i:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
            3 => $value('/i:Invoice/cbc:IssueDate') . 'T' . $value('/i:Invoice/cbc:IssueTime'),
            4 => $value('/i:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'),
            5 => $value('/i:Invoice/cac:TaxTotal[1]/cbc:TaxAmount'),
            6 => $hash,
            7 => $signature,
            8 => $certificate->publicKey(),
        ];
        if ($simplified) {
            $tags[9] = $certificate->signature();
        }

        return Qr::encode($tags);
    }

    private static function load(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        if (! @$document->loadXML($xml)) {
            throw new SigningError('The prepared invoice is not valid XML.');
        }

        return $document;
    }

    private static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('i', UblWriter::NS['']);
        foreach (['cac', 'cbc', 'ext'] as $prefix) {
            $xpath->registerNamespace($prefix, UblWriter::NS[$prefix]);
        }

        return $xpath;
    }
}

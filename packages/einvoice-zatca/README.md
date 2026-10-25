# hamada-emam-tech/einvoice-zatca

ZATCA (Fatoora) for [hamada-emam-tech/einvoice](https://github.com/hamada-emam-tech/einvoice): Saudi e-invoicing, phase 2.
It writes the UBL 2.1 XML, stamps it (ECDSA on secp256k1, XAdES, QR code), onboards your EGS unit, then clears or reports each invoice.

```bash
composer require hamada-emam-tech/einvoice-zatca guzzlehttp/guzzle
```

Installing it is enough: the core finds the `zatca` authority by itself. Any PSR-18 client works. Guzzle, Symfony HttpClient and php-http/discovery are picked up automatically, or pass `http_client`, `request_factory` and `stream_factory`.

> **Tested live** against ZATCA's developer portal: onboarding, the compliance check of all six document types (simplified and standard invoice, credit note, debit note) with no warnings, the production CSID, then one invoice reported and one cleared. See [what was verified](#what-was-verified-and-what-was-not) before you go to production.

## 1. Onboard the EGS unit (once per unit)

An EGS unit is one thing that issues invoices: a POS, a branch, an ERP instance. Each one gets its own key and certificate (CSID).

```php
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use HamadaEmamTech\Einvoice\Zatca\Onboarding\{CsrDetails, Onboarding};
use HamadaEmamTech\Einvoice\Zatca\ZatcaFactory;

$key = SoftwareKey::generate('secp256k1');
file_put_contents('/secrets/egs.key', $key->exportPrivateKey($passphrase));

$onboarding = Onboarding::create(['environment' => 'sandbox']);   // or 'simulation', 'production'

$csr = $onboarding->csr(new CsrDetails(
    commonName: 'POS-1',                               // your own name for this unit
    serialNumber: '1-MyERP|2-4.2|3-' . $unitSerial,     // 1-solution | 2-model or version | 3-unit serial
    organizationIdentifier: '399999999900003',          // VAT number: 15 digits, starts and ends with 3
    organizationUnit: 'Riyadh Branch',                  // for a VAT group: the member's 10-digit TIN
    organizationName: 'Example Trading Co',
    invoiceTypes: '1100',                               // standard | simplified | 0 | 0
    location: 'RRRD2929',                               // short national address
    industry: 'Retail',
), $key, $key->publicKeyPem());

// The OTP comes from the Fatoora portal. On the developer portal it is always 123345.
$compliance = $onboarding->complianceCsid($csr, $otp);
```

The CSR is built in PHP and signed through the key source: no `openssl.cnf`, no temp files.

Then sign one test document of every type you declared with the compliance certificate, and send them to the compliance check:

```php
$authority = (new ZatcaFactory())->create(['environment' => 'sandbox']);
$signer = $key->withCertificate($compliance->certificatePem());

$result = $onboarding->check($compliance,
    $authority->sign($authority->prepare($simplifiedInvoice), $signer),
    $authority->sign($authority->prepare($standardInvoice), $signer),
    // ... credit and debit notes too
);

$production = $onboarding->productionCsid($compliance);
file_put_contents('/secrets/csid.pem', $production->certificatePem());
// Store $production->binarySecurityToken and $production->secret() with your other secrets.
```

## 2. Configure

```php
use HamadaEmamTech\Einvoice\Einvoice;

$einvoice = new Einvoice([
    'default' => 'zatca',
    'authorities' => [
        'zatca' => [
            'environment' => 'production',                 // sandbox | simulation | production, or set 'base_url'
            'certificate' => getenv('ZATCA_CSID_TOKEN'),   // the binarySecurityToken
            'secret'      => getenv('ZATCA_CSID_SECRET'),
            'key'         => fn () => SoftwareKey::fromFile('/secrets/egs.key', $passphrase, '/secrets/csid.pem'),
        ],
    ],
]);
```

## 3. Issue invoices

```php
use HamadaEmamTech\Einvoice\Model\{Address, Invoice, Line, Money, Party};

$seller = new Party('Example Trading Co', '399999999900003',
    new Address('SA', 'Riyadh', 'King Fahd Road', '1234', 'Al Olaya', '12345', additionalNumber: '5678'),
    identifiers: ['CRN' => '1010010000']);

$invoice = new Invoice(
    number:   'INV-1001',
    issuedAt: new DateTimeImmutable('now', new DateTimeZone('Asia/Riyadh')),
    seller:   $seller,
    buyer:    null,                                      // no buyer VAT number: simplified (B2C)
    lines:    [Line::taxed('Widget', '3', Money::of('10.00', 'SAR'), 'S', '15')],
    currency: 'SAR',
    extra:    ['zatca' => [
        'icv'           => 1001,                         // invoice counter of this EGS unit, required
        'previous_hash' => $lastHashOfThisUnit,          // PIH; leave it out for the unit's first invoice
    ]],
);

$signed = $einvoice->sign($invoice);
// Save $signed->hash as the next previous_hash, and $signed->content as the invoice you keep.
// For a simplified invoice, print $signed->meta['qr'] as the QR code.

$result = $einvoice->submit($signed);
```

`submit()` picks the endpoint for you:

| Document | Endpoint | What you get back |
|---|---|---|
| Simplified (B2C): buyer without a VAT number | `POST /invoices/reporting/single` | `reportingStatus` REPORTED |
| Standard (B2B): buyer with a VAT number | `POST /invoices/clearance/single` | `clearanceStatus` CLEARED, and the cleared XML in `$result->accepted[0]->content` |
| Compliance mode (`'compliance' => true`) | `POST /compliance/invoices` | the validation result only |

For a standard invoice, share the **cleared** XML with the buyer: ZATCA adds its own stamp and QR to it.

ZATCA answers every call at once, so there is no `status()` call. Keep the `SubmissionResult`. A rejected document is data: `$result->rejected[0]->errors` holds ZATCA's codes and messages. Warnings on an accepted document are in `$result->accepted[0]->warnings`.

### `extra.zatca` keys

| Key | Default | Meaning |
|---|---|---|
| `icv` | required | Invoice counter of the EGS unit, a positive integer |
| `previous_hash` | the hash of `"0"` (first invoice) | Hash of the unit's previous invoice (PIH) |
| `simplified` | `true` when the buyer has no VAT number | Simplified (B2C) or standard (B2B) |
| `supply_date` | the issue date | Delivery date, standard invoices only |
| `payment_means` | `10` (cash) | UN/ECE 4461 code: 30 credit transfer, 42 bank account, 48 card, 1 not defined |
| `reason` | required on credit and debit notes | Why the note was issued |

Credit and debit notes are `InvoiceType::CreditNote` and `InvoiceType::DebitNote`, with `referencedInvoice` set to the original invoice number.

Tax categories: `S` (standard), `Z` (zero rated), `E` (exempt) and `O` (out of scope). Every category except `S` needs an exemption code (`VATEX-SA-...`) and reason on the line's tax: `Tax::percent('E', '0', $net, $reason, 'VATEX-SA-29')`.

## The ICV and PIH chain is yours

Each EGS unit numbers its invoices (ICV) and links each invoice to the hash of the one before it (PIH). This package cannot know your previous invoice. Store `$signed->hash` per unit and pass it as the next `previous_hash`, in order, from one place at a time (a queue or a lock per unit). The developer portal does not check this chain, so a broken chain will not show up in sandbox tests.

## What was verified, and what was not

Verified live on the developer portal (`https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal`):

- The CSR (template `TSTZATCA-Code-Signing`) is accepted. The compliance CSID carries our own public key.
- All six document types pass the compliance check with no errors and no warnings, including line discounts and lines in each tax category (S, Z, E, O).
- The production CSID is issued. With it, a simplified invoice is REPORTED and a standard invoice is CLEARED.
- A changed amount (hash), a changed signature and a changed QR are rejected.
- The reporting endpoint checks the signed-properties digest. It accepts only the form this package writes: the ds namespace on every ds element and a self-closing `DigestMethod`.

Points to confirm on simulation before production:

- **The sandbox does not verify the signature cryptographically.** It accepted a signature made with a different key, and in compliance mode a broken signed-properties digest. The unit tests do verify our signature with OpenSSL against the certificate, but only ZATCA's simulation shows what ZATCA itself enforces.
- **The sandbox production CSID is a sample certificate.** Its public key is not yours. The real one, from simulation or production, carries your key.
- The simulation and production base URLs (`/e-invoicing/simulation`, `/e-invoicing/core`) and their certificate templates (`PREZATCA-Code-Signing`, `ZATCA-Code-Signing`) come from ZATCA's documentation. They were not called here, because both need a real taxpayer account.
- In compliance mode ZATCA returns no cleared XML.

Not handled in this version: currencies other than SAR, document-level allowances and charges, prepayments, the special transaction flags (third party, nominal, export, summary, self-billed), and CSID renewal. Open an issue if you need one of them.

## Sources

- ZATCA, E-Invoicing for developers: <https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/default.aspx>. It links the XML implementation standard, the security features standard, the technical guideline and the developer portal.

## License

MIT

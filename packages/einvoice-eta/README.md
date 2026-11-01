# hamada-emam-tech/einvoice-eta

Egypt's eInvoicing (ETA) for [hamada-emam-tech/einvoice](https://github.com/hamada-emam-tech/einvoice).
It builds the v1.0 JSON document, writes ETA's canonical text, signs it as CAdES-BES with your RSA certificate, then submits, tracks, cancels and rejects documents.

```bash
composer require hamada-emam-tech/einvoice-eta guzzlehttp/guzzle
```

Installing it is enough: the core finds the `eta` authority by itself. Any PSR-18 client works. Guzzle, Symfony HttpClient and php-http/discovery are picked up automatically, or pass `http_client`, `request_factory` and `stream_factory`.

> **Not yet tested against ETA itself.** ETA has no public sandbox: pre-production needs a registered taxpayer's ERP credentials and certificate. The canonical text is checked byte for byte against ETA's own SDK sample, and every signature is checked by OpenSSL. See [what was verified](#what-was-verified-and-what-was-not) before you go live.

## 1. Configure

```php
use HamadaEmamTech\Einvoice\Einvoice;

$einvoice = new Einvoice([
    'default' => 'eta',
    'authorities' => [
        'eta' => [
            'environment'   => 'preprod',                 // preprod | production
            'client_id'     => getenv('ETA_CLIENT_ID'),   // the ERP system registered on the ETA portal
            'client_secret' => getenv('ETA_CLIENT_SECRET'),
            'activity_code' => '4620',                    // your taxpayer activity code
            'key'           => fn () => $keySource,       // see "Signing" below
            // 'on_behalf_of' => '100000001',             // intermediaries: the taxpayer's registration number
        ],
    ],
]);
```

The package logs in with OAuth 2 client credentials, keeps the token until a minute before it expires, and logs in again once if ETA answers 401.

## 2. Issue invoices

```php
use HamadaEmamTech\Einvoice\Model\{Address, Invoice, Line, Money, Party, Tax};

$seller = new Party('Example Trading Co', '100000001',
    new Address('EG', 'Nasr City', '12 Abbas El Akkad St', '12', region: 'Cairo'),
    identifiers: ['branch_id' => '0']);

$price = Money::of('1000.00', 'EGP');
$invoice = new Invoice(
    number:   'INV-1001',                                // sent as internalID
    issuedAt: new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')),
    seller:   $seller,
    buyer:    new Party('Example Buyer Co', '200000002', new Address('EG', 'Dokki', '5 Tahrir St', '5', region: 'Giza')),
    lines:    [new Line('Laptop', '2', $price,
                  [Tax::percent('T1', '14', $price->times('2'), exemptionCode: 'V009')],   // VAT, subtype V009
                  itemCode: 'EG-100000001-1001')],        // your GS1 or EGS code
    currency: 'EGP',
);

$signed = $einvoice->sign($invoice);
$result = $einvoice->submit($signed);              // or submit several signed documents in one batch

foreach ($result->accepted as $doc) { $doc->authorityId; }   // ETA's UUID: store it
foreach ($result->rejected as $doc) { $doc->errors; }        // ETA's codes, messages and fields
```

**ETA validates later.** "Accepted" in the submission means accepted for processing. Ask for the final result a few seconds later:

```php
$einvoice->status($uuid);   // Pending (submitted), Accepted (valid), Rejected (invalid or rejected), Cancelled
```

### How the document is built

| Invoice field | ETA field |
|---|---|
| `number` | `internalID` |
| `issuedAt` | `dateTimeIssued`, in UTC |
| `type` | `documentType`: I, C (credit note), D (debit note) |
| `referencedInvoice` | `references`: the ETA UUID of the original document |
| `seller->taxNumber`, `identifiers['branch_id']` (default `0`) | `issuer.id`, `issuer.address.branchID` |
| `buyer` | `receiver`: B with a tax number, F with an address outside EG, P otherwise (`identifiers['national_id']`); no buyer is P with no ID. Set `identifiers['type']` to choose. |
| `Address`: `region`, `city`, `street`, `buildingNumber`, `postalCode` | `governate`, `regionCity`, `street`, `buildingNumber`, `postalCode` |
| `Line->itemCode`, `extra['item_type']` (default `EGS`), `extra['unit_type']` (default `EA`), `extra['internal_code']` | `itemCode`, `itemType`, `unitType`, `internalCode` |
| `Tax('T1', rate, exemptionCode: 'V009')` | `taxableItems`: taxType T1, subType V009 |

Amounts are computed here, exactly, at ETA's 5 decimals: sales total = quantity × unit price, net = sales − discount, VAT = net × rate, total = net + VAT. `Tax::$amount` is not used, so the document always matches ETA's own arithmetic. A line discount is sent as `{"rate": 0, "amount": ...}`, the form ETA's own samples use.

The builder covers EGP invoices with VAT (T1). For anything else, either:

- set ETA fields in `extra['eta']`; each key replaces the built one: `extra: ['eta' => ['purchaseOrderReference' => 'PO-77']]`, or
- build the whole document yourself and use `prepareDocument()`:

```php
$eta = $einvoice->authority('eta');
$signed = $eta->sign($eta->prepareDocument($json), $keySource);
```

Amounts in your JSON are kept exactly as written (`14.00` stays `14.00`), because the signature covers that text. In `extra['eta']`, write amounts as `Number::of('14.00')` (`HamadaEmamTech\Einvoice\Eta\Json\Number`), never as floats.

## 3. Signing

ETA accepts one kind of signature: CAdES-BES, made with an RSA certificate issued to the taxpayer or its representative by an approved Egyptian provider. That certificate usually lives on a USB token.

Pass any `KeySource` that can:

- make a CAdES-BES signature itself (`SignatureFormat::CadesBes`), or
- make a raw RSA SHA-256 signature (`SignatureFormat::RawRsa`) and return the certificate.

In the second case this package builds the CMS: SHA-256, the signer certificate, and the signed attributes content type, signing time, message digest and signingCertificateV2. So the token only signs a few hundred bytes. ECDSA keys are refused, because ETA does not accept them.

For a key exported to PEM files (for tests, or a certificate kept in an HSM export):

```php
$keySource = SoftwareKey::fromFile('/secrets/eta.key', $passphrase, '/secrets/eta-cert.pem');
```

Most USB tokens do not let the key out. Run [signing-agent](https://github.com/hamada-emam-tech/signing-agent) on the machine with the token and use [einvoice-keysource-agent](https://github.com/hamada-emam-tech/einvoice-keysource-agent):

```php
use HamadaEmamTech\Einvoice\KeySource\Agent\AgentKey;

$keySource = AgentKey::connect('http://127.0.0.1:8765', getenv('SIGNING_AGENT_API_KEY'), 'main:01');
```

The token signs a few hundred bytes (the signed attributes); this package builds the CAdES around that signature. This path was checked end to end on SoftHSM, a software PKCS#11 token: OpenSSL verifies the result.

## 4. After submitting

```php
$eta = $einvoice->authority('eta');

$eta->details($uuid);                   // the document, its status and validationResults
$eta->submission($submissionUuid);      // overallStatus and each document's status
$eta->cancel($uuid, 'Wrong buyer');     // issuer, within ETA's cancellation period
$eta->reject($uuid, 'Not ordered');     // receiver
$eta->declineCancellation($uuid);       // receiver refuses the issuer's cancellation
$eta->declineRejection($uuid);          // issuer refuses the receiver's rejection
$pdf = $eta->printout($uuid);           // PDF bytes
```

A call ETA refuses as a whole throws `AuthorityError` with ETA's code in `$e->authorityCode`: `DuplicateSubmission` (HTTP 422, the message says when to retry), `BadStructure`, `MaximumSizeExceeded`, `IncorrectSubmitter`, `OperationPeriodOver`, `IncorrectState`, `ActiveReferencingDocuments`. The client secret never appears in an error.

## What was verified, and what was not

Verified by the tests in this package:

- The canonical text of ETA's own SDK sample document matches ETA's published serialized text byte for byte.
- Every signature verifies with OpenSSL (`openssl cms -verify`) over the canonical text. A changed amount does not verify. The certificate hash in signingCertificateV2 is the signer certificate's.
- Numbers keep their text from prepare through sign and submit (`14.00`, `0.333`).
- Amounts: 0.333 × 10.50 gives 3.49650, VAT 14% gives 0.48951.
- A simulated taxpayer day with the key on a USB token (ETA API and Signing Agent both scripted): login once, sign through the agent, submit, status Submitted then Valid, cancel. Also an unplugged token and a locked PIN: the signing fails with the agent's code and nothing is sent to ETA. See [the simulation test](https://github.com/hamada-emam-tech/einvoice-php/blob/main/tests/Simulation/EtaThroughSigningAgentTest.php).

Taken from ETA's documentation (sdk.invoicing.eta.gov.eg) and its Postman collection, not yet called:

- The URLs, the login (Basic client credentials, `scope=InvoicingAPI`, the `onbehalfof` header), the submission, details, submission, state, decline and PDF endpoints, and their error codes.
- The v1.0 document fields, the document types, `signatureType` I, and the discount form.

To confirm on pre-production before production:

- That ETA accepts this CMS layout: content type 1.2.840.113549.1.7.5 (from ETA's signing sample), and signingCertificateV2 with the default SHA-256 algorithm.
- That ETA's validation accepts the computed amounts at 5 decimals with no rounding warnings.

Run the live test with your own pre-production credentials: see `tests/Live/PreprodTest.php`.

Not handled by the builder in this version: currencies other than EGP, taxes other than VAT (T1), taxable fees, value difference, items discount and document-level extra discount (all sent as 0), export documents (EI, EC, ED) and e-receipts. Use `extra['eta']` or `prepareDocument()` for these.

## Sources

- ETA eInvoicing SDK: <https://sdk.invoicing.eta.gov.eg>. The API, the document types (Invoice v1.0), the document serialization approach and the signature creation pages.

## License

MIT

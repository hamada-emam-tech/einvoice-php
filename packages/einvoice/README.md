# hamada-emam-tech/einvoice

One PHP interface for tax-authority e-invoicing: sign every invoice and submit it. Install the core, then only the authority you need.

```bash
composer require hamada-emam-tech/einvoice           # the core: model, software keys, a fake authority for tests
composer require hamada-emam-tech/einvoice-zatca     # Saudi ZATCA (coming soon)
composer require hamada-emam-tech/einvoice-eta       # Egypt ETA (planned)
```

Installing an authority package is enough. The core finds it automatically.

> **Status:** v0.1 ships the core, `SoftwareKey`, and the `fake` authority. Authority packages are being built one at a time, ZATCA first. You can already write your own with `extend()` (see [writing an authority or key source](https://github.com/hamada-emam-tech/einvoice-php/blob/main/docs/writing-an-authority.md)).

## Two separate choices: the authority and the key

The **authority** decides the document format and the API: UBL XML for ZATCA, canonical JSON for ETA.
The **key source** decides where the private key lives. The authority never knows which one it got.

| Key source | Where the key lives | Package |
|---|---|---|
| `SoftwareKey` | A PEM file or secret your app holds (ZATCA) | in the core |
| Signing Agent | A USB token or HSM, through PKCS#11 (Egypt ETA) | `einvoice-keysource-agent` (planned) |
| CSC | A cloud signing service (CSC API) | `einvoice-keysource-csc` (planned) |

## Use it

```php
use HamadaEmamTech\Einvoice\Einvoice;
use HamadaEmamTech\Einvoice\Model\{Invoice, Line, Money, Party};
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;

$einvoice = new Einvoice([
    'default'     => 'zatca',
    'authorities' => [
        'zatca' => [
            'environment' => 'sandbox',
            // A KeySource, or a callable returning one, so the key is only read when signing.
            'key' => fn () => SoftwareKey::fromFile('/secrets/egs.key', getenv('EGS_KEY_PASS'), '/secrets/csid.pem'),
        ],
    ],
]);

$invoice = new Invoice(
    number:   'INV-1001',
    issuedAt: new DateTimeImmutable(),
    seller:   new Party('Seller Co', '300000000000003', identifiers: ['CRN' => '1010010000']),
    buyer:    new Party('Buyer Co', '311111111111113'),
    lines:    [Line::taxed('Widget', '3', Money::of('10.00', 'SAR'), 'S', '15')],
    currency: 'SAR',
);

$signed = $einvoice->sign($invoice);    // prepare + sign. Store $signed before you submit.
$result = $einvoice->submit($signed);   // ideally from a queue, with retries
```

Signing for many tenants or devices? Pass the key in: `$einvoice->sign($invoice, $tenantKey)`.

## Money is never a float

Amounts are integer minor units plus a currency. Parse them from strings:

```php
Money::of('115.50', 'SAR');            // 11550 halalas
Money::of('1.234', 'KWD');             // 3-decimal currencies are handled
Money::of('10.005', 'SAR');            // throws: round it yourself, nothing is rounded silently
Money::of('100.00', 'SAR')->percent('15');  // 15.00, half away from zero
```

A float changes the canonical string, and one changed character breaks the signature.

## Partial success is normal

One submission can come back with accepted and rejected documents together (Egypt ETA does this). A rejected document is data, not an exception:

```php
foreach ($result->rejected as $rejected) {
    foreach ($rejected->errors as $problem) {
        log("{$rejected->invoiceNumber}: [{$problem->code}] {$problem->message}");
    }
}
```

`AuthorityError` is thrown only when the call itself fails: authentication, an outage, a malformed request.

## Keys

```php
$key = SoftwareKey::generate('secp256k1');                 // ZATCA uses secp256k1
file_put_contents('egs.key', $key->exportPrivateKey($passphrase));

$key = SoftwareKey::fromFile('egs.key', $passphrase)->withCertificate($csidPem);
```

`SoftwareKey` stays out of `var_dump()`, `dd()` and error trackers.

## Test without a real authority

```php
$einvoice = new Einvoice(['default' => 'fake']);
$fake = $einvoice->authority();                  // HamadaEmamTech\Einvoice\Testing\FakeAuthority
$fake->reject('INV-2', 'Buyer VAT number is invalid');

$result = $einvoice->issue($invoice);
$fake->assertSubmitted(fn ($doc) => $doc->invoiceNumber === 'INV-1001');
```

`FakeKeySource` signs anything and remembers it: `$key->assertSigned()`.

## Authorities

| Package | Authority | Key | Status |
|---|---|---|---|
| `hamada-emam-tech/einvoice-zatca` | ZATCA, Saudi Arabia (Fatoora) | software key, secp256k1 | next |
| `hamada-emam-tech/einvoice-eta` | ETA, Egypt | USB token or HSM, CAdES-BES | planned |

Framework-agnostic: PHP 8.2+ with ext-openssl, no framework dependency.

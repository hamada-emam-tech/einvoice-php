# Writing an authority or key-source package

Each tax authority (ZATCA, ETA…) and each key source (Signing Agent, CSC…) is its own Composer package that requires the core.

## 1. An authority package

```
packages/einvoice-<name>/
├── composer.json
├── src/<Name>Factory.php      implements AuthorityFactory
├── src/<Name>Authority.php    implements TaxAuthority
├── tests/
│   └── Fixtures/*.golden.*    expected payloads, byte for byte
└── README.md
```

`composer.json` declares the factory. This is how the core discovers the authority:

```json
{
    "name": "hamada-emam-tech/einvoice-<name>",
    "require": {
        "php": "^8.2",
        "hamada-emam-tech/einvoice": "^0.1",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0"
    },
    "extra": {
        "einvoice": { "authorities": { "<name>": "HamadaEmamTech\\Einvoice\\<Name>\\<Name>Factory" } }
    }
}
```

The factory gets the authority's config block **without** `"key"`. Keys reach the authority only through `sign()`, so one instance can sign for many taxpayers.

## 2. Rules every authority follows

1. **`prepare()` is deterministic.** Same invoice, same bytes. Cover it with golden-file tests: one changed character breaks the signature.
2. **No floats, anywhere.** Read amounts with `Money::toDecimal()`, quantities and rates as the strings they came in as. When parsing the authority's responses, keep amounts as strings (`JSON_BIGINT_AS_STRING`, no `(float)` casts). Float and date parsing silently changing a canonical string is a real bug seen in production signers.
3. **Ask the key source for the format you need** (`SignatureFormat::RawEcdsa` for ZATCA, `CadesBes` for ETA). Check `supports()` first and throw `SigningError::unsupported()` with a clear message.
4. **Canonicalize in the authority, never in the key source.** A key source only signs the bytes or hash it is given.
5. **Partial success is data.** A document the authority refuses goes in `SubmissionResult::$rejected` with its `Problem`s. Throw `AuthorityError` only when the call itself fails, with the HTTP status and the authority's code. Never put credentials or tokens in a message.
6. **Use PSR-18 for HTTP.** Never hard-code Guzzle. The consumer brings their own client.
7. **Keep it stateless.** Invoice counters, previous-invoice hashes (ZATCA), and access-token caching are the consumer's storage. Take them as input (in `Invoice::$extra` or config) and document the storage in the README instead of shipping it.
8. **Ship a fake PSR-18 client** that reproduces the authority's real traps: partial acceptance, expired tokens, duplicate submissions.

## 3. A key-source package

Implement `KeySource`:

| Method | Rule |
|---|---|
| `name()` | A label for logs, e.g. `agent:token-1`. Never key material or a PIN. |
| `supports($format)` | Only the formats this key can really produce. |
| `sign($data, $format)` | Return the signature bytes (DER for ECDSA and CMS). Throw `SigningError` on failure, without key material in the message. |
| `certificate()` | The signing certificate as PEM, or null. |

A key source that talks to hardware (the Signing Agent) must never send the PIN or the PKCS#11 library path over the wire. Those stay in the agent's local config.

Key-source packages don't need discovery: the consumer builds the key and puts it under `"key"` in the config.

## 4. Register it in the monorepo

1. Add the folder under `packages/`.
2. Add its autoload paths to the root `composer.json`.
3. Add a line to the matrix in `.github/workflows/split.yml`.
4. Create the empty target repo `hamada-emam-tech/einvoice-<name>` and submit it to Packagist once.

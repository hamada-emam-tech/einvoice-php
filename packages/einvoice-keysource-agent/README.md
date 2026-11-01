# hamada-emam-tech/einvoice-keysource-agent

A `KeySource` for [hamada-emam-tech/einvoice](https://github.com/hamada-emam-tech/einvoice) that signs with a **USB token or HSM** through [signing-agent](https://github.com/hamada-emam-tech/signing-agent), a small local service that talks PKCS#11 to the token.
The private key never leaves the token. This class sends the bytes to sign and gets the signature back.

```bash
composer require hamada-emam-tech/einvoice-keysource-agent guzzlehttp/guzzle
```

Any PSR-18 client works. Guzzle, Symfony HttpClient and php-http/discovery are picked up automatically, or pass `http_client`, `request_factory` and `stream_factory` in the last argument.

## Use

1. Install and start signing-agent on the machine with the token. Find the certificate ID:

   ```bash
   signing-agent certificates -c agent.toml     # "id": "main:01"
   ```

2. Pass the key source to your authority:

   ```php
   use HamadaEmamTech\Einvoice\KeySource\Agent\AgentKey;

   $key = AgentKey::connect('http://127.0.0.1:8765', getenv('SIGNING_AGENT_API_KEY'), 'main:01');

   $einvoice = new Einvoice([
       'default' => 'eta',
       'authorities' => ['eta' => [/* ... */, 'key' => fn () => $key]],
   ]);
   ```

`$key->certificates()` lists every certificate the agent sees, if you want to let a user choose.

## What it supports

| Certificate key | Formats |
|---|---|
| RSA | `SignatureFormat::RawRsa` (PKCS#1 v1.5 over SHA-256) |
| EC | `SignatureFormat::RawEcdsa` (DER, over SHA-256) |

CAdES, XAdES and PDF signatures are built by the authority package around the raw signature. `einvoice-eta` does this for ETA's CAdES-BES.

The certificate is read from the agent once and kept for the life of the object.

## Errors

Every failure throws `SigningError` with the agent's code and message, for example:

- `Signing agent at http://127.0.0.1:8765 is not reachable: ... Is it running?`
- `Signing agent: HTTP 503 pin_incorrect. Token "main" refused the PIN. ...`
- `Signing agent: no certificate main:09. Run `signing-agent certificates` to list the IDs.`

The API key never appears in an error or a dump.

## Network

Plain `http://` is accepted only for this machine (`127.0.0.1`, `localhost`, `::1`). An agent on another machine must use `https://`, so the API key and the data never cross the network in clear.

## Verified

- Unit tests against a scripted agent that signs with a real key.
- End to end: signing-agent on SoftHSM 2.7 (a software PKCS#11 token), this key source, and `einvoice-eta` building the CAdES-BES; `openssl cms -verify` accepts the result.
- A simulated ETA flow through the agent (login, sign, submit, status, cancel, an unplugged token, a locked PIN): [the simulation test](https://github.com/hamada-emam-tech/einvoice-php/blob/main/tests/Simulation/EtaThroughSigningAgentTest.php).
- Not yet run on a physical USB token or against ETA.

Live test against a running agent:

```bash
AGENT_LIVE=1 SIGNING_AGENT_URL=http://127.0.0.1:8765 SIGNING_AGENT_API_KEY=... \
SIGNING_AGENT_CERTIFICATE=main:01 vendor/bin/phpunit --group live
```

## License

MIT

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Signing;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use OpenSSLAsymmetricKey;

/**
 * A private key your system holds (PEM, optionally encrypted). Signs ECDSA or RSA over SHA-256
 * with OpenSSL, no extra software. This is what ZATCA uses.
 *
 *     $key = SoftwareKey::generate('secp256k1');         // new EGS unit
 *     file_put_contents('egs.key', $key->exportPrivateKey($passphrase));
 *
 *     $key = SoftwareKey::fromFile('egs.key', $passphrase)->withCertificate($csidPem);
 */
final class SoftwareKey implements KeySource
{
    private function __construct(
        private readonly OpenSSLAsymmetricKey $key,
        private readonly ?string $certificatePem = null,
    ) {
    }

    public static function fromPem(string $privateKeyPem, ?string $passphrase = null, ?string $certificatePem = null): self
    {
        $key = openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($key === false) {
            self::clearOpensslErrors();
            throw new SigningError('Could not read the private key. Check the PEM and the passphrase.');
        }

        return new self($key, $certificatePem);
    }

    public static function fromFile(string $privateKeyPath, ?string $passphrase = null, ?string $certificatePath = null): self
    {
        $read = static function (string $path): string {
            $contents = is_file($path) ? file_get_contents($path) : false;

            return $contents === false ? throw new SigningError("Cannot read {$path}.") : $contents;
        };

        return self::fromPem($read($privateKeyPath), $passphrase, $certificatePath === null ? null : $read($certificatePath));
    }

    /** A new EC key. ZATCA requires "secp256k1". */
    public static function generate(string $curve = 'prime256v1'): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        if ($key === false) {
            self::clearOpensslErrors();
            throw new SigningError("Could not generate an EC key on curve {$curve}.");
        }

        return new self($key);
    }

    /** The same key with its certificate attached, e.g. once ZATCA has issued the CSID. */
    public function withCertificate(string $certificatePem): self
    {
        return new self($this->key, $certificatePem);
    }

    public function name(): string
    {
        return 'software';
    }

    public function supports(SignatureFormat $format): bool
    {
        return match ($format) {
            SignatureFormat::RawEcdsa => $this->type() === OPENSSL_KEYTYPE_EC,
            SignatureFormat::RawRsa => $this->type() === OPENSSL_KEYTYPE_RSA,
            default => false,
        };
    }

    public function sign(string $data, SignatureFormat $format): string
    {
        if (! $this->supports($format)) {
            throw SigningError::unsupported($this->name(), $format);
        }
        if (! openssl_sign($data, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            self::clearOpensslErrors();
            throw new SigningError('OpenSSL failed to sign.');
        }

        return $signature;
    }

    public function certificate(): ?string
    {
        return $this->certificatePem;
    }

    public function publicKeyPem(): string
    {
        return (string) (openssl_pkey_get_details($this->key)['key'] ?? '');
    }

    /** PEM to store the key. Pass a passphrase unless the storage is already encrypted. */
    public function exportPrivateKey(?string $passphrase = null): string
    {
        if (! openssl_pkey_export($this->key, $pem, $passphrase)) {
            self::clearOpensslErrors();
            throw new SigningError('Could not export the private key.');
        }

        return $pem;
    }

    /** Keep the key out of var_dump(), dd() and error trackers. */
    public function __debugInfo(): array
    {
        return ['type' => $this->type() === OPENSSL_KEYTYPE_EC ? 'ec' : 'rsa', 'certificate' => $this->certificatePem !== null];
    }

    private function type(): int
    {
        return (int) (openssl_pkey_get_details($this->key)['type'] ?? -1);
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Signing;

use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Zatca\Support\Der;

/**
 * The CSID certificate, read the way ZATCA's signature rules need it.
 *
 * Accepts a PEM, the base64 body, or the binarySecurityToken exactly as the CSID API returns it
 * (base64 of the base64 body).
 */
final class Certificate
{
    private function __construct(
        /** The base64 DER body, one line, no PEM armour. */
        public readonly string $body,
    ) {
    }

    public static function from(string $certificate): self
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificate) ?? '';
        $der = base64_decode($body, true);
        if ($der !== false && str_starts_with($der, 'MII')) {
            // a binarySecurityToken: one more layer of base64
            $body = $der;
            $der = base64_decode($body, true);
        }
        if ($der === false || $der === '' || ord($der[0]) !== 0x30) {
            throw new SigningError('The certificate is not a PEM, a base64 certificate or a ZATCA binarySecurityToken.');
        }

        return new self($body);
    }

    public function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split($this->body, 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    /** The binarySecurityToken form, used for the API's Basic auth. */
    public function token(): string
    {
        return base64_encode($this->body);
    }

    /** xades:CertDigest: base64 of the hex SHA-256 of the base64 body (ZATCA's rule, not plain XAdES). */
    public function hash(): string
    {
        return base64_encode(hash('sha256', $this->body));
    }

    /** ds:X509IssuerName: RDNs most specific first, joined by ", " (e.g. "CN=TSZEINVOICE-SubCA-1, DC=extgazt, DC=gov, DC=local"). */
    public function issuer(): string
    {
        $parts = [];
        foreach ($this->parsed()['issuer'] ?? [] as $name => $values) {
            foreach ((array) $values as $value) {
                $parts[] = "{$name}={$value}";
            }
        }

        return implode(', ', array_reverse($parts));
    }

    /** ds:X509SerialNumber, in decimal. */
    public function serialNumber(): string
    {
        $hex = (string) ($this->parsed()['serialNumberHex'] ?? '');

        return $hex === '' ? (string) ($this->parsed()['serialNumber'] ?? '') : self::hexToDecimal($hex);
    }

    /** Serials are often wider than 64 bits; convert digit by digit so neither bcmath nor gmp is needed. */
    private static function hexToDecimal(string $hex): string
    {
        $decimal = [0];
        foreach (str_split(strtolower(ltrim($hex, '0')) ?: '0') as $digit) {
            $carry = (int) hexdec($digit);
            foreach ($decimal as $i => $value) {
                $value = $value * 16 + $carry;
                $decimal[$i] = $value % 10;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $decimal[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }

        return implode('', array_reverse($decimal));
    }

    /** The public key as DER (SubjectPublicKeyInfo): QR tag 8. */
    public function publicKey(): string
    {
        $key = openssl_pkey_get_public($this->pem());
        $pem = $key === false ? '' : (string) (openssl_pkey_get_details($key)['key'] ?? '');

        return (string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true);
    }

    /** The CA's signature on this certificate: QR tag 9. */
    public function signature(): string
    {
        $children = Der::children((string) base64_decode($this->body, true));
        [$tag, , $content] = $children[2] ?? [0, '', ''];
        if ($tag !== 0x03) {
            throw new SigningError('Could not read the signature of the certificate.');
        }

        return substr($content, 1);    // drop the "unused bits" byte
    }

    /** @return array<string, mixed> */
    private function parsed(): array
    {
        $parsed = openssl_x509_parse($this->pem());
        if ($parsed === false) {
            throw new SigningError('OpenSSL could not read the certificate.');
        }

        return $parsed;
    }
}

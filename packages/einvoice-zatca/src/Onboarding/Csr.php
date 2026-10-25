<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Onboarding;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use HamadaEmamTech\Einvoice\Zatca\Environment;
use HamadaEmamTech\Einvoice\Zatca\Support\Der;

/**
 * Builds the CSR ZATCA asks for, signed through a KeySource. No openssl.cnf, no temp files,
 * and it works the same for a key file and for a key that never leaves a token.
 *
 *     $key = SoftwareKey::generate('secp256k1');
 *     $csr = Csr::create($details, $key, $key->publicKeyPem(), Environment::Sandbox);
 */
final class Csr
{
    private const OID = [
        'CN' => '2.5.4.3', 'OU' => '2.5.4.11', 'O' => '2.5.4.10', 'C' => '2.5.4.6',
        'SN' => '2.5.4.4', 'UID' => '0.9.2342.19200300.100.1.1', 'title' => '2.5.4.12',
        'registeredAddress' => '2.5.4.26', 'businessCategory' => '2.5.4.15',
    ];

    /** @return string the CSR as PEM */
    public static function create(CsrDetails $details, KeySource $key, string $publicKeyPem, Environment $environment): string
    {
        $publicKey = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $publicKeyPem) ?? '', true);
        if ($publicKey === false || $publicKey === '') {
            throw new SigningError('The public key is not a PEM.');
        }

        $subject = Der::sequence(
            self::rdn('C', $details->countryCode),
            self::rdn('OU', $details->organizationUnit),
            self::rdn('O', $details->organizationName),
            self::rdn('CN', $details->commonName),
        );
        $alternativeName = Der::sequence(Der::context(4, Der::sequence(
            self::rdn('SN', $details->serialNumber),
            self::rdn('UID', $details->organizationIdentifier),
            self::rdn('title', $details->invoiceTypes),
            self::rdn('registeredAddress', $details->location),
            self::rdn('businessCategory', $details->industry),
        )));
        $extensions = Der::sequence(
            Der::sequence(Der::oid('1.3.6.1.4.1.311.20.2'), Der::octets(Der::utf8($environment->certificateTemplate()))),
            Der::sequence(Der::oid('2.5.29.17'), Der::octets($alternativeName)),
        );
        $info = Der::sequence(
            Der::integer(0),
            $subject,
            $publicKey,
            Der::context(0, Der::sequence(Der::oid('1.2.840.113549.1.9.14'), Der::set($extensions))),
        );

        $signature = $key->sign($info, SignatureFormat::RawEcdsa);
        $csr = Der::sequence($info, Der::sequence(Der::oid('1.2.840.10045.4.3.2')), Der::bits($signature));

        return "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($csr), 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";
    }

    private static function rdn(string $name, string $value): string
    {
        $string = $name === 'C' ? Der::printable($value) : Der::utf8($value);

        return Der::set(Der::sequence(Der::oid(self::OID[$name]), $string));
    }
}

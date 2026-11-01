<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Signing;

use DateTimeImmutable;
use DateTimeInterface;
use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Eta\Support\Der;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;

/**
 * The CAdES-BES signature ETA expects: a detached CMS SignedData over the canonical text,
 * SHA-256, with the signer certificate and three signed attributes (content type, signing
 * time, message digest) plus signingCertificateV2.
 *
 * Any key source that makes a raw RSA signature and holds a certificate can sign this way,
 * so a USB token only has to sign the attribute bytes; the CMS is built here. ETA accepts RSA
 * only: "a valid RSA signature created using one of the approved eSeal or natural person
 * signature certificates in Egypt" (Invoice v1.0, Signature Validation).
 *
 * The content type is 1.2.840.113549.1.7.5, as in the signing sample of ETA's SDK. The
 * certificate hash in signingCertificateV2 uses the default SHA-256 algorithm.
 */
final class Cades
{
    private const SIGNED_DATA = '1.2.840.113549.1.7.2';
    private const ETA_CONTENT_TYPE = '1.2.840.113549.1.7.5';
    private const SHA256 = '2.16.840.1.101.3.4.2.1';
    private const RSA = '1.2.840.113549.1.1.1';
    private const ATTR_CONTENT_TYPE = '1.2.840.113549.1.9.3';
    private const ATTR_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';
    private const ATTR_SIGNING_TIME = '1.2.840.113549.1.9.5';
    private const ATTR_SIGNING_CERTIFICATE_V2 = '1.2.840.113549.1.9.16.2.47';

    /** The DER CMS for $content, signed by $key. */
    public static function sign(string $content, KeySource $key, ?DateTimeInterface $signingTime = null): string
    {
        if ($key->supports(SignatureFormat::CadesBes)) {
            return $key->sign($content, SignatureFormat::CadesBes);
        }

        if (! $key->supports(SignatureFormat::RawRsa)) {
            throw new SigningError(sprintf('Key source "%s" cannot sign for ETA: ETA accepts RSA signatures only, as CAdES-BES or a raw RSA signature with a certificate.', $key->name()));
        }
        $pem = $key->certificate() ?? throw new SigningError(sprintf('Key source "%s" has no certificate. ETA signatures carry the signer certificate.', $key->name()));
        $certificate = self::der($pem);

        $attributes = [
            self::attribute(self::ATTR_CONTENT_TYPE, Der::oid(self::ETA_CONTENT_TYPE)),
            self::attribute(self::ATTR_SIGNING_TIME, Der::utcTime($signingTime ?? new DateTimeImmutable())),
            self::attribute(self::ATTR_MESSAGE_DIGEST, Der::octets(hash('sha256', $content, true))),
            self::attribute(self::ATTR_SIGNING_CERTIFICATE_V2, Der::sequence(Der::sequence(Der::sequence(Der::octets(hash('sha256', $certificate, true)))))),
        ];
        // Signed as a SET OF (tag 0x31); stored as [0] IMPLICIT (tag 0xA0).
        $signedAttributes = Der::setOf(...$attributes);
        $signature = $key->sign($signedAttributes, SignatureFormat::RawRsa);

        $sha256 = Der::sequence(Der::oid(self::SHA256));
        $signerInfo = Der::sequence(
            Der::integer(1),
            self::issuerAndSerial($certificate),
            $sha256,
            "\xA0" . substr($signedAttributes, 1),
            Der::sequence(Der::oid(self::RSA), Der::null()),
            Der::octets($signature),
        );

        return Der::sequence(
            Der::oid(self::SIGNED_DATA),
            Der::context(0, Der::sequence(
                Der::integer(3),
                Der::set($sha256),
                Der::sequence(Der::oid(self::ETA_CONTENT_TYPE)),
                Der::context(0, $certificate),
                Der::set($signerInfo),
            )),
        );
    }

    private static function attribute(string $oid, string $value): string
    {
        return Der::sequence(Der::oid($oid), Der::set($value));
    }

    /** IssuerAndSerialNumber, copied byte for byte from the certificate. */
    private static function issuerAndSerial(string $certificate): string
    {
        $tbs = Der::children($certificate)[0][1] ?? throw new SigningError('The certificate is empty.');
        $fields = Der::children($tbs);
        $offset = ($fields[0][0] ?? 0) === 0xA0 ? 1 : 0;
        $serial = $fields[$offset][1] ?? throw new SigningError('The certificate has no serial number.');
        $issuer = $fields[$offset + 2][1] ?? throw new SigningError('The certificate has no issuer.');

        return Der::sequence($issuer, $serial);
    }

    private static function der(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);
        if ($der === false || $der === '' || openssl_x509_read($pem) === false) {
            throw new SigningError('The key source certificate is not a PEM X.509 certificate.');
        }

        return $der;
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Tests\Unit;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use HamadaEmamTech\Einvoice\Zatca\Signing\Certificate;
use HamadaEmamTech\Einvoice\Zatca\Signing\Qr;
use HamadaEmamTech\Einvoice\Zatca\Signing\Stamp;
use HamadaEmamTech\Einvoice\Zatca\Tests\Fixtures\FakeZatca;
use HamadaEmamTech\Einvoice\Zatca\ZatcaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StampTest extends TestCase
{
    #[Test]
    public function a_certificate_loads_from_pem_body_or_binary_security_token(): void
    {
        $pem = FakeZatca::certificate();
        $body = Certificate::from($pem)->body;

        self::assertSame($body, Certificate::from($body)->body);
        self::assertSame($body, Certificate::from(base64_encode($body))->body);
        self::assertSame(base64_encode($body), Certificate::from($pem)->token());
        self::assertSame(base64_encode(hash('sha256', $body)), Certificate::from($pem)->hash());
    }

    #[Test]
    public function a_serial_wider_than_64_bits_is_written_in_decimal(): void
    {
        $certificate = Certificate::from(FakeZatca::certificate());

        self::assertSame('379112742831380471835263969587287663520528387', $certificate->serialNumber());
        // RFC 4514 order (reversed DER), as ZATCA's own SDK writes it.
        self::assertSame('C=SA, O=Example Trading Co, CN=Test EGS', $certificate->issuer());
    }

    #[Test]
    public function qr_tlv_round_trips_and_refuses_values_over_255_bytes(): void
    {
        $tags = [1 => 'شركة المثال', 2 => '399999999900003', 6 => str_repeat('h', 44)];
        self::assertSame($tags, Qr::decode(Qr::encode($tags)));

        $this->expectException(InvalidInvoice::class);
        Qr::encode([1 => str_repeat('x', 256)]);
    }

    #[Test]
    public function the_stamp_signs_the_invoice_hash_that_the_signed_document_still_has(): void
    {
        $authority = (new ZatcaFactory())->create((new FakeZatca())->config());
        $signed = $authority->sign($authority->prepare(FakeZatca::invoice()), FakeZatca::key());

        self::assertSame($signed->hash, Stamp::invoiceHash($signed->content));

        $tags = Qr::decode((string) $signed->meta['qr']);
        $public = openssl_pkey_get_public(FakeZatca::certificate());
        self::assertNotFalse($public);
        self::assertSame(1, openssl_verify((string) base64_decode((string) $signed->hash), (string) base64_decode($tags[7]), $public, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function the_qr_carries_the_seller_totals_hash_and_key(): void
    {
        $authority = (new ZatcaFactory())->create((new FakeZatca())->config());
        $simplified = $authority->sign($authority->prepare(FakeZatca::invoice()), FakeZatca::key());
        $standard = $authority->sign($authority->prepare(FakeZatca::invoice('INV-2', FakeZatca::buyer())), FakeZatca::key());

        $tags = Qr::decode((string) $simplified->meta['qr']);
        self::assertSame('Example Trading Co', $tags[1]);
        self::assertSame('399999999900003', $tags[2]);
        self::assertSame('2026-10-25T10:30:00', $tags[3]);
        self::assertSame('132.25', $tags[4]);
        self::assertSame('17.25', $tags[5]);
        self::assertSame($simplified->hash, $tags[6]);
        self::assertSame(Certificate::from(FakeZatca::certificate())->publicKey(), $tags[8]);
        self::assertSame(Certificate::from(FakeZatca::certificate())->signature(), $tags[9]);

        self::assertArrayNotHasKey(9, Qr::decode((string) $standard->meta['qr']), 'tag 9 is for simplified invoices only');
        self::assertStringContainsString('<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">' . $simplified->meta['qr'] . '<', $simplified->content);
    }

    #[Test]
    public function the_signed_properties_digest_matches_the_form_the_reporting_endpoint_accepts(): void
    {
        $authority = (new ZatcaFactory())->create((new FakeZatca())->config());
        $signed = $authority->sign($authority->prepare(FakeZatca::invoice()), FakeZatca::key())->content;

        self::assertSame(1, preg_match('#<xades:SignedProperties.*?</xades:SignedProperties>#', $signed, $embedded));
        self::assertSame(1, preg_match('#URI="\#xadesSignedProperties"><ds:DigestMethod[^>]+/><ds:DigestValue>([^<]+)<#', $signed, $digest));

        // Reporting checks this hash: the ds namespace on every ds element and a self-closing DigestMethod.
        $forHashing = (string) preg_replace('#<ds:(\w+)#', '<ds:$1 xmlns:ds="http://www.w3.org/2000/09/xmldsig#"', $embedded[0] ?? '');
        self::assertStringNotContainsString('></ds:DigestMethod>', $forHashing);
        self::assertSame(base64_encode(hash('sha256', $forHashing)), $digest[1] ?? null);
    }

    #[Test]
    public function changing_the_signed_invoice_changes_its_hash(): void
    {
        $authority = (new ZatcaFactory())->create((new FakeZatca())->config());
        $signed = $authority->sign($authority->prepare(FakeZatca::invoice()), FakeZatca::key());

        self::assertNotSame($signed->hash, Stamp::invoiceHash(str_replace('>132.25<', '>132.26<', $signed->content)));
    }

    #[Test]
    public function signing_needs_the_csid_certificate_on_the_key(): void
    {
        $authority = (new ZatcaFactory())->create((new FakeZatca())->config());

        $this->expectException(SigningError::class);
        $authority->sign($authority->prepare(FakeZatca::invoice()), SoftwareKey::generate('secp256k1'));
    }
}

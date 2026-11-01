<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Tests\Unit;

use DateTimeImmutable;
use HamadaEmamTech\Einvoice\Eta\Signing\Cades;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\FakeEta;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\OpenSsl;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use HamadaEmamTech\Einvoice\Testing\FakeKeySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Our CMS is checked by OpenSSL, not by us: a signature that only our own code accepts proves nothing. */
final class CadesTest extends TestCase
{
    #[Test]
    public function openssl_verifies_the_detached_signature_over_the_canonical_text(): void
    {
        $key = FakeEta::key();
        $canonical = '"ISSUER""NAME""شركة""TOTALAMOUNT""14.00"';

        $cms = Cades::sign($canonical, $key, new DateTimeImmutable('2026-11-01 10:00:00 UTC'));

        [$ok, $output] = OpenSsl::cms($cms, $canonical, ['cms', '-verify', '-binary', '-noverify']);
        self::assertTrue($ok, $output);

        [, $printed] = OpenSsl::cms($cms, $canonical, ['cms', '-cmsout', '-print']);
        self::assertStringContainsString('eContentType: pkcs7-digestData', $printed);
        self::assertStringContainsString('id-smime-aa-signingCertificateV2', $printed);
        self::assertStringContainsString('UTCTIME:Nov  1 10:00:00 2026 GMT', $printed);
        self::assertStringContainsString('sha256', $printed);
        self::assertStringContainsString('rsaEncryption', $printed);
    }

    #[Test]
    public function an_ecdsa_key_is_refused_because_eta_accepts_rsa_only(): void
    {
        $this->expectException(SigningError::class);
        $this->expectExceptionMessage('RSA signatures only');

        Cades::sign('x', FakeEta::key('ec'));
    }

    #[Test]
    public function a_changed_text_does_not_verify(): void
    {
        $cms = Cades::sign('"TOTALAMOUNT""14.00"', FakeEta::key('rsa'));

        [$ok] = OpenSsl::cms($cms, '"TOTALAMOUNT""14.0"', ['cms', '-verify', '-binary', '-noverify']);

        self::assertFalse($ok);
    }

    #[Test]
    public function the_certificate_hash_in_signing_certificate_v2_is_the_signer_certificate(): void
    {
        $key = FakeEta::key('rsa');
        $cms = Cades::sign('x', $key);

        openssl_x509_export((string) $key->certificate(), $pem);
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        self::assertStringContainsString(hash('sha256', (string) $der, true), $cms);
    }

    #[Test]
    public function a_key_without_a_certificate_cannot_sign_for_eta(): void
    {
        $this->expectException(SigningError::class);
        $this->expectExceptionMessage('has no certificate');

        openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]) ?: throw new \RuntimeException('openssl_pkey_new failed'), $rsa);

        Cades::sign('x', SoftwareKey::fromPem($rsa));
    }

    #[Test]
    public function a_key_source_that_makes_cades_itself_is_used_as_is(): void
    {
        $key = new FakeKeySource();

        self::assertSame($key->sign('x', SignatureFormat::CadesBes), Cades::sign('x', $key));
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Tests\Unit;

use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SoftwareKeyTest extends TestCase
{
    #[Test]
    public function a_secp256k1_key_signs_ecdsa_that_openssl_verifies(): void
    {
        $key = SoftwareKey::generate('secp256k1');
        $signature = $key->sign('invoice-hash', SignatureFormat::RawEcdsa);

        self::assertSame(1, openssl_verify('invoice-hash', $signature, $key->publicKeyPem(), OPENSSL_ALGO_SHA256));
        self::assertSame(0, openssl_verify('tampered', $signature, $key->publicKeyPem(), OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function an_exported_encrypted_key_loads_back_with_its_passphrase(): void
    {
        $original = SoftwareKey::generate('secp256k1');
        $pem = $original->exportPrivateKey('s3cret');
        self::assertStringContainsString('ENCRYPTED', $pem);

        $loaded = SoftwareKey::fromPem($pem, 's3cret', '-----BEGIN CERTIFICATE-----');
        $signature = $loaded->sign('data', SignatureFormat::RawEcdsa);
        self::assertSame(1, openssl_verify('data', $signature, $original->publicKeyPem(), OPENSSL_ALGO_SHA256));
        self::assertSame('-----BEGIN CERTIFICATE-----', $loaded->certificate());
    }

    #[Test]
    public function a_wrong_passphrase_fails_without_leaking_the_key(): void
    {
        $pem = SoftwareKey::generate()->exportPrivateKey('right');

        try {
            SoftwareKey::fromPem($pem, 'wrong');
            self::fail('Expected SigningError');
        } catch (SigningError $e) {
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    #[Test]
    public function an_ec_key_refuses_formats_it_cannot_produce(): void
    {
        $key = SoftwareKey::generate();
        self::assertFalse($key->supports(SignatureFormat::CadesBes));

        $this->expectException(SigningError::class);
        $key->sign('data', SignatureFormat::RawRsa);
    }

    #[Test]
    public function the_key_stays_out_of_debug_output(): void
    {
        $dump = print_r(SoftwareKey::generate(), true);

        self::assertStringNotContainsString('PRIVATE', $dump);
        self::assertStringContainsString('ec', $dump);
    }
}

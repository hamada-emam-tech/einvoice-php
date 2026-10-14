<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Tests\Unit;

use HamadaEmamTech\Einvoice\Discovery\AuthorityRegistry;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Einvoice;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotInstalled;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use HamadaEmamTech\Einvoice\Testing\FakeAuthority;
use HamadaEmamTech\Einvoice\Testing\FakeKeySource;
use HamadaEmamTech\Einvoice\Tests\Fixtures\DummyFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EinvoiceTest extends TestCase
{
    private function registry(): AuthorityRegistry
    {
        return new AuthorityRegistry(__DIR__ . '/../Fixtures/installed.json');
    }

    #[Test]
    public function an_installed_authority_is_discovered_and_never_sees_the_key(): void
    {
        $key = new FakeKeySource();
        $einvoice = new Einvoice(['default' => 'zatca', 'authorities' => ['zatca' => ['environment' => 'sandbox', 'key' => $key]]], $this->registry());

        self::assertContains('zatca', $einvoice->installed());
        $einvoice->sign(InvoiceTest::invoice());
        self::assertSame(['environment' => 'sandbox'], DummyFactory::$lastConfig);
        $key->assertSigned();
    }

    #[Test]
    public function a_missing_authority_tells_you_which_package_to_install(): void
    {
        $einvoice = new Einvoice(['default' => 'eta', 'authorities' => ['eta' => []]], $this->registry());

        $this->expectException(AuthorityNotInstalled::class);
        $this->expectExceptionMessage('composer require hamada-emam-tech/einvoice-eta');
        $einvoice->authority();
    }

    #[Test]
    public function an_authority_without_a_key_fails_clearly_when_signing(): void
    {
        $einvoice = new Einvoice(['default' => 'zatca', 'authorities' => ['zatca' => []]], $this->registry());

        $this->expectException(AuthorityNotConfigured::class);
        $this->expectExceptionMessage('no "key"');
        $einvoice->sign(InvoiceTest::invoice());
    }

    #[Test]
    public function a_key_passed_in_wins_over_the_configured_one(): void
    {
        $configured = new FakeKeySource();
        $tenant = new FakeKeySource();
        $einvoice = new Einvoice(['default' => 'fake', 'authorities' => ['fake' => ['key' => $configured]]], $this->registry());

        $einvoice->sign(InvoiceTest::invoice(), $tenant);

        $tenant->assertSigned();
        $configured->assertNothingSigned();
    }

    #[Test]
    public function a_lazy_key_is_only_loaded_when_used(): void
    {
        $loaded = 0;
        $einvoice = new Einvoice(['default' => 'fake', 'authorities' => ['fake' => ['key' => function () use (&$loaded) {
            ++$loaded;

            return new FakeKeySource();
        }]]], $this->registry());

        $einvoice->authority();
        self::assertSame(0, $loaded);
        $einvoice->sign(InvoiceTest::invoice());
        self::assertSame(1, $loaded);
    }

    #[Test]
    public function a_real_software_key_signs_the_prepared_payload(): void
    {
        $key = SoftwareKey::generate('secp256k1');
        $signed = (new Einvoice(['default' => 'fake', 'authorities' => ['fake' => ['key' => $key]]], $this->registry()))
            ->sign(InvoiceTest::invoice());

        self::assertSame(SignatureFormat::RawEcdsa->value, $signed->meta['format']);
        self::assertSame(1, openssl_verify($signed->content, base64_decode((string) $signed->meta['signature']), $key->publicKeyPem(), OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function partial_success_returns_accepted_and_rejected_together(): void
    {
        $einvoice = new Einvoice(['default' => 'fake'], $this->registry());
        /** @var FakeAuthority $fake */
        $fake = $einvoice->authority();
        $fake->reject('INV-2', 'Buyer VAT number is invalid');

        $result = $einvoice->submit($einvoice->sign(InvoiceTest::invoice('INV-1')), $einvoice->sign(InvoiceTest::invoice('INV-2')));

        self::assertFalse($result->allAccepted());
        self::assertNotNull($accepted = $result->accepted('INV-1'));
        self::assertSame('Buyer VAT number is invalid', $result->rejected('INV-2')?->errors[0]->message);
        self::assertSame(DocumentStatus::Accepted, $einvoice->status((string) $accepted->authorityId));
        $fake->assertSubmitted(fn (SignedDocument $d) => $d->invoiceNumber === 'INV-2');
    }

    #[Test]
    public function preparing_is_deterministic_and_matches_the_golden_file(): void
    {
        $fake = new FakeAuthority();
        $payload = $fake->prepare(InvoiceTest::invoice())->payload;

        self::assertSame($payload, $fake->prepare(InvoiceTest::invoice())->payload);
        self::assertStringEqualsFile(__DIR__ . '/../Fixtures/fake-invoice.golden.json', $payload);
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Tests\Unit;

use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Exceptions\EinvoiceException;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use HamadaEmamTech\Einvoice\Zatca\Csid;
use HamadaEmamTech\Einvoice\Zatca\Onboarding\CsrDetails;
use HamadaEmamTech\Einvoice\Zatca\Onboarding\Onboarding;
use HamadaEmamTech\Einvoice\Zatca\Signing\Certificate;
use HamadaEmamTech\Einvoice\Zatca\Support\Der;
use HamadaEmamTech\Einvoice\Zatca\Tests\Fixtures\FakeZatca;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OnboardingTest extends TestCase
{
    #[Test]
    public function the_csr_is_signed_by_the_key_and_carries_the_zatca_fields(): void
    {
        $key = SoftwareKey::generate('secp256k1');
        $pem = Onboarding::create((new FakeZatca())->config())->csr(self::details(), $key, $key->publicKeyPem());

        self::assertSame(['C' => 'SA', 'OU' => 'Riyadh Branch', 'O' => 'Example Trading Co', 'CN' => 'POS-1'], openssl_csr_get_subject($pem, true) ?: null);
        $public = openssl_csr_get_public_key($pem);
        self::assertNotFalse($public);
        self::assertSame($key->publicKeyPem(), openssl_pkey_get_details($public)['key'] ?? null);

        // The CSR signature covers CertificationRequestInfo; check it the way a CA does.
        $der = (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));
        [$info, , $bits] = Der::children($der);
        self::assertSame(1, openssl_verify($info[1], substr($bits[2], 1), $key->publicKeyPem(), OPENSSL_ALGO_SHA256));

        foreach (['TSTZATCA-Code-Signing', '1-ExampleERP|2-1.0|3-unit-0001', '399999999900003', '1100', 'RRRD2929', 'Retail'] as $field) {
            self::assertStringContainsString($field, $der);
        }
    }

    #[Test]
    public function each_environment_asks_for_its_own_certificate_template(): void
    {
        $key = SoftwareKey::generate('secp256k1');
        $csr = static fn (string $environment): string => (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', Onboarding::create((new FakeZatca())->config(['environment' => $environment]))->csr(self::details(), $key, $key->publicKeyPem())));

        self::assertStringContainsString('PREZATCA-Code-Signing', $csr('simulation'));
        self::assertStringContainsString("\x0c\x12ZATCA-Code-Signing", $csr('production'));
    }

    #[Test]
    public function the_compliance_csid_is_asked_with_the_otp_and_the_base64_csr(): void
    {
        $token = Certificate::from(FakeZatca::certificate())->token();
        $fake = (new FakeZatca())->reply(200, ['requestID' => 1234567890, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => $token, 'secret' => 'compliance-secret']);

        $csid = Onboarding::create($fake->config())->complianceCsid("-----BEGIN CERTIFICATE REQUEST-----\nMIIB\n-----END CERTIFICATE REQUEST-----\n", '123345');

        self::assertStringEndsWith('/compliance', (string) $fake->requests[0]->getUri());
        self::assertSame('123345', $fake->requests[0]->getHeaderLine('OTP'));
        self::assertFalse($fake->requests[0]->hasHeader('Authorization'));
        self::assertSame(base64_encode("-----BEGIN CERTIFICATE REQUEST-----\nMIIB\n-----END CERTIFICATE REQUEST-----\n"), $fake->lastBody()['csr']);
        self::assertSame('1234567890', $csid->requestId);
        self::assertSame('compliance-secret', $csid->secret());
        self::assertSame(FakeZatca::certificate(), $csid->certificatePem());
        self::assertStringNotContainsString('compliance-secret', print_r($csid, true));
    }

    #[Test]
    public function the_production_csid_is_asked_with_the_compliance_request_id(): void
    {
        $fake = (new FakeZatca())->reply(200, ['requestID' => 4321, 'binarySecurityToken' => 'TUlJQg==', 'secret' => 'production-secret']);
        $compliance = new Csid('Y29tcGxpYW5jZQ==', 'compliance-secret', '1234567890');

        $production = Onboarding::create($fake->config())->productionCsid($compliance);

        self::assertStringEndsWith('/production/csids', (string) $fake->requests[0]->getUri());
        self::assertSame(['compliance_request_id' => '1234567890'], $fake->lastBody());
        self::assertSame($compliance->basic(), $fake->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('production-secret', $production->secret());
    }

    #[Test]
    public function a_refused_onboarding_step_names_zatca_errors(): void
    {
        $fake = (new FakeZatca())->reply(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]);

        $this->expectException(AuthorityError::class);
        $this->expectExceptionMessage('Invalid-OTP');
        Onboarding::create($fake->config())->complianceCsid('csr', '000000');
    }

    #[Test]
    public function csr_details_reject_values_zatca_would_refuse(): void
    {
        foreach ([['organizationIdentifier' => '123'], ['invoiceTypes' => '0011'], ['serialNumber' => 'POS-1']] as $wrong) {
            try {
                new CsrDetails(...$wrong + self::fields());
                self::fail('Expected EinvoiceException for ' . key($wrong));
            } catch (EinvoiceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private static function details(): CsrDetails
    {
        return new CsrDetails(...self::fields());
    }

    /** @return array<string, string> */
    private static function fields(): array
    {
        return [
            'commonName' => 'POS-1',
            'serialNumber' => '1-ExampleERP|2-1.0|3-unit-0001',
            'organizationIdentifier' => '399999999900003',
            'organizationUnit' => 'Riyadh Branch',
            'organizationName' => 'Example Trading Co',
            'invoiceTypes' => '1100',
            'location' => 'RRRD2929',
            'industry' => 'Retail',
        ];
    }
}

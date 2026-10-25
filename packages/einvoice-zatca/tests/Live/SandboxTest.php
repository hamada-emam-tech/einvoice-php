<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Tests\Live;

use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use HamadaEmamTech\Einvoice\Zatca\Onboarding\CsrDetails;
use HamadaEmamTech\Einvoice\Zatca\Onboarding\Onboarding;
use HamadaEmamTech\Einvoice\Zatca\Tests\Fixtures\FakeZatca;
use HamadaEmamTech\Einvoice\Zatca\ZatcaFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The whole flow against ZATCA's public developer portal (OTP 123345, no account).
 * Run it with: ZATCA_LIVE=1 vendor/bin/phpunit --group live
 */
#[Group('live')]
final class SandboxTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('ZATCA_LIVE') !== '1') {
            self::markTestSkipped('Set ZATCA_LIVE=1 to call the ZATCA developer portal.');
        }
    }

    #[Test]
    public function onboard_check_every_type_then_report_and_clear(): void
    {
        $key = SoftwareKey::generate('secp256k1');
        $onboarding = Onboarding::create(['environment' => 'sandbox']);
        $csr = $onboarding->csr(new CsrDetails(
            commonName: 'TST-EGS-1',
            serialNumber: '1-EinvoicePHP|2-0.1|3-' . bin2hex(random_bytes(8)),
            organizationIdentifier: '399999999900003',
            organizationUnit: 'Riyadh Branch',
            organizationName: 'Example Trading Co',
            invoiceTypes: '1100',
            location: 'RRRD2929',
            industry: 'Retail',
        ), $key, $key->publicKeyPem());

        $compliance = $onboarding->complianceCsid($csr, '123345');
        $authority = (new ZatcaFactory())->create(['environment' => 'sandbox']);
        $signer = $key->withCertificate($compliance->certificatePem());

        $documents = [];
        $previous = null;
        $note = ['reason' => 'Returned goods'];
        foreach ([[null, InvoiceType::Invoice], [null, InvoiceType::CreditNote], [null, InvoiceType::DebitNote], [FakeZatca::buyer(), InvoiceType::Invoice], [FakeZatca::buyer(), InvoiceType::CreditNote], [FakeZatca::buyer(), InvoiceType::DebitNote]] as $i => [$buyer, $type]) {
            $zatca = ['icv' => $i + 1] + ($previous === null ? [] : ['previous_hash' => $previous]) + ($type === InvoiceType::Invoice ? [] : $note);
            $documents[] = $signed = $authority->sign($authority->prepare(FakeZatca::invoice('LIVE-' . ($i + 1), $buyer, $type, $zatca)), $signer);
            $previous = $signed->hash;
        }

        $checked = $onboarding->check($compliance, ...$documents);
        self::assertTrue($checked->allAccepted(), (string) json_encode(array_map(fn ($r) => $r->errors, $checked->rejected)));
        foreach ($checked->accepted as $accepted) {
            self::assertSame([], $accepted->warnings, $accepted->invoiceNumber);
        }

        // The sandbox issues a sample production certificate that is not bound to this key; it accepts the documents anyway.
        $production = $onboarding->productionCsid($compliance);
        $live = (new ZatcaFactory())->create(['environment' => 'sandbox', 'certificate' => $production->binarySecurityToken, 'secret' => $production->secret()]);
        $productionSigner = $key->withCertificate($production->certificatePem());

        $result = $live->submit(
            $live->sign($live->prepare(FakeZatca::invoice('LIVE-7', null, InvoiceType::Invoice, ['icv' => 7, 'previous_hash' => $previous])), $productionSigner),
            $live->sign($live->prepare(FakeZatca::invoice('LIVE-8', FakeZatca::buyer(), InvoiceType::Invoice, ['icv' => 8])), $productionSigner),
        );

        self::assertTrue($result->allAccepted(), (string) json_encode(array_map(fn ($r) => $r->errors, $result->rejected)));
        self::assertSame('REPORTED', $result->accepted[0]->raw['reportingStatus'] ?? null);
        self::assertSame('CLEARED', $result->accepted[1]->raw['clearanceStatus'] ?? null);
        self::assertStringContainsString('<cbc:ID>LIVE-8</cbc:ID>', (string) $result->accepted[1]->content);
    }
}

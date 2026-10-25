<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Tests\Unit;

use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Zatca\Signing\Certificate;
use HamadaEmamTech\Einvoice\Zatca\Tests\Fixtures\FakeZatca;
use HamadaEmamTech\Einvoice\Zatca\Ubl\InvoiceOptions;
use HamadaEmamTech\Einvoice\Zatca\ZatcaAuthority;
use HamadaEmamTech\Einvoice\Zatca\ZatcaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZatcaAuthorityTest extends TestCase
{
    private const PASS = ['infoMessages' => [], 'warningMessages' => [], 'errorMessages' => [], 'status' => 'PASS'];

    #[Test]
    public function a_simplified_invoice_is_reported_with_basic_auth(): void
    {
        $fake = (new FakeZatca())->reply(200, ['validationResults' => self::PASS, 'reportingStatus' => 'REPORTED']);
        $authority = $this->authority($fake);
        $signed = $this->sign($authority, FakeZatca::invoice());

        $result = $authority->submit($signed);

        self::assertTrue($result->allAccepted());
        $request = $fake->requests[0];
        self::assertSame('https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/invoices/reporting/single', (string) $request->getUri());
        self::assertSame('0', $request->getHeaderLine('Clearance-Status'));
        self::assertSame('V2', $request->getHeaderLine('Accept-Version'));
        self::assertSame('Basic ' . base64_encode($this->token() . ':s3cret'), $request->getHeaderLine('Authorization'));
        self::assertSame(['invoiceHash' => $signed->hash, 'uuid' => $signed->meta['uuid'], 'invoice' => base64_encode($signed->content)], $fake->lastBody());
    }

    #[Test]
    public function a_standard_invoice_is_cleared_and_returns_the_cleared_xml(): void
    {
        $fake = (new FakeZatca())->reply(200, [
            'validationResults' => ['warningMessages' => [['code' => 'BR-KSA-08', 'message' => 'Seller ID', 'category' => 'KSA']]] + self::PASS,
            'clearanceStatus' => 'CLEARED',
            'clearedInvoice' => base64_encode('<Invoice>cleared</Invoice>'),
        ]);
        $authority = $this->authority($fake);

        $result = $authority->submit($this->sign($authority, FakeZatca::invoice('INV-2', FakeZatca::buyer())));

        self::assertStringEndsWith('/invoices/clearance/single', (string) $fake->requests[0]->getUri());
        self::assertSame('1', $fake->requests[0]->getHeaderLine('Clearance-Status'));
        self::assertSame('<Invoice>cleared</Invoice>', $result->accepted[0]->content);
        self::assertSame('BR-KSA-08', $result->accepted[0]->warnings[0]->code);
    }

    #[Test]
    public function errors_from_zatca_make_a_rejected_document(): void
    {
        $fake = (new FakeZatca())->reply(400, [
            'validationResults' => ['errorMessages' => [['code' => 'invalid-invoice-hash', 'message' => 'The invoice hash API body does not match the calculated Hash', 'category' => 'INVOICE_HASHING_ERRORS']], 'status' => 'ERROR'],
            'reportingStatus' => 'NOT_REPORTED',
        ]);
        $authority = $this->authority($fake);

        $result = $authority->submit($this->sign($authority, FakeZatca::invoice()));

        self::assertFalse($result->allAccepted());
        self::assertSame('INV-1', $result->rejected[0]->invoiceNumber);
        self::assertSame('invalid-invoice-hash', $result->rejected[0]->errors[0]->code);
    }

    #[Test]
    public function compliance_mode_sends_every_type_to_the_compliance_endpoint(): void
    {
        $fake = (new FakeZatca())
            ->reply(200, ['validationResults' => self::PASS, 'reportingStatus' => 'REPORTED'])
            ->reply(200, ['validationResults' => self::PASS, 'clearanceStatus' => 'CLEARED']);
        $authority = $this->authority($fake, ['compliance' => true]);

        $result = $authority->submit(
            $this->sign($authority, FakeZatca::invoice()),
            $this->sign($authority, FakeZatca::invoice('INV-2', FakeZatca::buyer())),
        );

        self::assertCount(2, $result->accepted);
        foreach ($fake->requests as $request) {
            self::assertStringEndsWith('/compliance/invoices', (string) $request->getUri());
            self::assertFalse($request->hasHeader('Clearance-Status'));
        }
    }

    #[Test]
    public function a_refused_csid_and_a_switched_off_clearance_throw(): void
    {
        $authority = $this->authority((new FakeZatca())->reply(401));
        try {
            $authority->submit($this->sign($authority, FakeZatca::invoice()));
            self::fail('Expected AuthorityError');
        } catch (AuthorityError $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }

        $authority = $this->authority((new FakeZatca())->reply(303));
        $this->expectException(AuthorityError::class);
        $authority->submit($this->sign($authority, FakeZatca::invoice('INV-2', FakeZatca::buyer())));
    }

    #[Test]
    public function submit_needs_the_csid_in_the_config(): void
    {
        $fake = new FakeZatca();
        $authority = (new ZatcaFactory())->create($fake->config());
        self::assertInstanceOf(ZatcaAuthority::class, $authority);

        $this->expectException(AuthorityNotConfigured::class);
        $authority->submit($this->sign($authority, FakeZatca::invoice()));
    }

    #[Test]
    public function prepare_is_deterministic_and_carries_the_zatca_meta(): void
    {
        $authority = $this->authority(new FakeZatca());

        $first = $authority->prepare(FakeZatca::invoice());
        $second = $authority->prepare(FakeZatca::invoice());

        self::assertSame($first->payload, $second->payload);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $first->meta['uuid']);
        self::assertSame(1, $first->meta['icv']);
        self::assertTrue($first->meta['simplified']);
        self::assertFalse($authority->prepare(FakeZatca::invoice('INV-2', FakeZatca::buyer()))->meta['simplified']);
        self::assertStringContainsString(InvoiceOptions::FIRST_PREVIOUS_HASH, $first->payload);
    }

    #[Test]
    public function the_ubl_names_the_document_type_and_the_transaction_kind(): void
    {
        $authority = $this->authority(new FakeZatca());
        $note = ['icv' => 2, 'reason' => 'Returned goods', 'previous_hash' => 'cHJldmlvdXM='];

        $simplified = $authority->prepare(FakeZatca::invoice())->payload;
        $credit = $authority->prepare(FakeZatca::invoice('CN-1', FakeZatca::buyer(), InvoiceType::CreditNote, $note))->payload;
        $debit = $authority->prepare(FakeZatca::invoice('DN-1', null, InvoiceType::DebitNote, $note))->payload;

        self::assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">388</cbc:InvoiceTypeCode>', $simplified);
        self::assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">381</cbc:InvoiceTypeCode>', $credit);
        self::assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">383</cbc:InvoiceTypeCode>', $debit);
        self::assertStringContainsString('<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>INV-1</cbc:ID>', $credit);
        self::assertStringContainsString('Returned goods', $credit);
        self::assertStringContainsString('cHJldmlvdXM=', $credit);
    }

    #[Test]
    public function the_invoice_counter_and_a_note_reason_are_required(): void
    {
        $authority = $this->authority(new FakeZatca());

        try {
            $authority->prepare(FakeZatca::invoice(zatca: []));
            self::fail('Expected InvalidInvoice for the missing icv');
        } catch (InvalidInvoice) {
        }

        $this->expectException(InvalidInvoice::class);
        $authority->prepare(FakeZatca::invoice('CN-1', null, InvoiceType::CreditNote, ['icv' => 2]));
    }

    #[Test]
    public function zatca_has_no_status_endpoint(): void
    {
        $this->expectException(AuthorityError::class);
        $this->authority(new FakeZatca())->status('any');
    }

    /** @param array<string, mixed> $config */
    private function authority(FakeZatca $fake, array $config = []): ZatcaAuthority
    {
        $authority = (new ZatcaFactory())->create($fake->config($config + ['certificate' => $this->token(), 'secret' => 's3cret']));
        self::assertInstanceOf(ZatcaAuthority::class, $authority);

        return $authority;
    }

    private function sign(ZatcaAuthority $authority, \HamadaEmamTech\Einvoice\Model\Invoice $invoice): SignedDocument
    {
        return $authority->sign($authority->prepare($invoice), FakeZatca::key());
    }

    private function token(): string
    {
        return Certificate::from(FakeZatca::certificate())->token();
    }
}

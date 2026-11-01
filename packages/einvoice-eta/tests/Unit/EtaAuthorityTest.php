<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Tests\Unit;

use HamadaEmamTech\Einvoice\Eta\EtaFactory;
use HamadaEmamTech\Einvoice\Eta\Json\Json;
use HamadaEmamTech\Einvoice\Eta\Signing\Canonical;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\FakeEta;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\OpenSsl;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Address;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Money;
use HamadaEmamTech\Einvoice\Model\Party;
use HamadaEmamTech\Einvoice\Model\Tax;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EtaAuthorityTest extends TestCase
{
    #[Test]
    public function the_document_follows_invoice_v1_0_with_exact_5_decimal_amounts(): void
    {
        $document = self::document((new FakeEta())->authority()->prepare(FakeEta::invoice())->payload);

        self::assertSame('I', $document['documentType']);
        self::assertSame('1.0', $document['documentTypeVersion']);
        self::assertSame('2026-11-01T10:30:00Z', $document['dateTimeIssued'], 'Cairo time 12:30 is 10:30 UTC');
        self::assertSame('4620', $document['taxpayerActivityCode']);
        self::assertSame('INV-1', $document['internalID']);
        self::assertSame(['branchID' => '0', 'country' => 'EG', 'governate' => 'Cairo', 'regionCity' => 'Nasr City', 'street' => '12 Abbas El Akkad St', 'buildingNumber' => '12'], $document['issuer']['address']);
        self::assertSame(['B', '100000001', 'Example Trading Co'], [$document['issuer']['type'], $document['issuer']['id'], $document['issuer']['name']]);
        self::assertSame(['B', '200000002', 'Governate' => 'Giza'], [$document['receiver']['type'], $document['receiver']['id'], 'Governate' => $document['receiver']['address']['governate']]);
        self::assertArrayNotHasKey('references', $document);

        [$laptop, $cable] = $document['invoiceLines'];
        self::assertSame(['EGS', 'EG-100000001-1001', 'EA', '2'], [$laptop['itemType'], $laptop['itemCode'], $laptop['unitType'], (string) $laptop['quantity']]);
        self::assertSame(
            ['salesTotal' => '2000.00000', 'discount' => '100.00000', 'netTotal' => '1900.00000', 'tax' => '266.00000', 'total' => '2166.00000', 'amountEGP' => '1000.00'],
            self::amounts($laptop),
        );
        // 0.333 × 10.50 = 3.4965 exactly; 14% of it is 0.48951.
        self::assertSame(
            ['salesTotal' => '3.49650', 'discount' => '0.00000', 'netTotal' => '3.49650', 'tax' => '0.48951', 'total' => '3.98601', 'amountEGP' => '10.50'],
            self::amounts($cable),
        );
        self::assertSame('KGM', $cable['unitType']);
        self::assertSame(['T1', 'V009', '14'], [$laptop['taxableItems'][0]['taxType'], $laptop['taxableItems'][0]['subType'], (string) $laptop['taxableItems'][0]['rate']]);

        self::assertSame('2003.49650', (string) $document['totalSalesAmount']);
        self::assertSame('100.00000', (string) $document['totalDiscountAmount']);
        self::assertSame('1903.49650', (string) $document['netAmount']);
        self::assertSame([['taxType' => 'T1', 'amount' => '266.48951']], array_map(fn (array $t) => ['taxType' => $t['taxType'], 'amount' => (string) $t['amount']], $document['taxTotals']));
        self::assertSame('2169.98601', (string) $document['totalAmount']);
    }

    #[Test]
    public function prepare_is_deterministic_and_amounts_are_json_numbers(): void
    {
        $authority = (new FakeEta())->authority();

        $payload = $authority->prepare(FakeEta::invoice())->payload;

        self::assertSame($payload, $authority->prepare(FakeEta::invoice())->payload);
        self::assertStringContainsString('"totalAmount":2169.98601', $payload);
        self::assertStringContainsString('"quantity":0.333', $payload);
    }

    #[Test]
    public function credit_and_debit_notes_reference_the_original_uuid(): void
    {
        $authority = (new FakeEta())->authority();

        $credit = self::document($authority->prepare(FakeEta::invoice('CN-1', type: InvoiceType::CreditNote))->payload);
        $debit = self::document($authority->prepare(FakeEta::invoice('DN-1', type: InvoiceType::DebitNote))->payload);

        self::assertSame(['C', ['ORIGINALUUID0000000000000A']], [$credit['documentType'], $credit['references']]);
        self::assertSame(['D', ['ORIGINALUUID0000000000000A']], [$debit['documentType'], $debit['references']]);
    }

    #[Test]
    public function receivers_are_typed_business_person_or_foreign(): void
    {
        $authority = (new FakeEta())->authority();
        $receiver = fn (?Party $buyer) => self::document($authority->prepare(FakeEta::invoice(buyer: $buyer))->payload)['receiver'];

        $person = $receiver(new Party('Mona Ali', null, new Address('EG', 'Dokki', '5 Tahrir St', '5', region: 'Giza'), ['national_id' => '29001011234567']));
        $foreign = $receiver(new Party('Gulf Buyer LLC', '300000000000003', new Address('SA', 'Riyadh', 'King Fahd Rd', '1', region: 'Riyadh')));

        self::assertSame(['P', '29001011234567'], [$person['type'], $person['id']]);
        self::assertSame(['F', '300000000000003'], [$foreign['type'], $foreign['id']]);
    }

    #[Test]
    public function extra_eta_fields_replace_the_built_ones(): void
    {
        $invoice = FakeEta::invoice(extra: ['eta' => ['purchaseOrderReference' => 'PO-77', 'taxpayerActivityCode' => '9478']]);

        $document = self::document((new FakeEta())->authority()->prepare($invoice)->payload);

        self::assertSame(['PO-77', '9478'], [$document['purchaseOrderReference'], $document['taxpayerActivityCode']]);
    }

    #[Test]
    public function the_signature_is_cades_over_the_canonical_text_and_openssl_verifies_it(): void
    {
        $authority = (new FakeEta())->authority();
        $prepared = $authority->prepare(FakeEta::invoice());

        $signed = $authority->sign($prepared, FakeEta::key());

        $document = self::document($signed->content);
        self::assertCount(1, $document['signatures']);
        self::assertSame('I', $document['signatures'][0]['signatureType']);
        $canonical = Canonical::serialize(self::document($prepared->payload));
        self::assertSame(hash('sha256', $canonical), $signed->hash);
        self::assertSame($canonical, Canonical::serialize($document), 'the signature does not change what is signed');
        self::assertTrue(OpenSsl::verifies((string) base64_decode($document['signatures'][0]['value'], true), $canonical));
        self::assertSame(substr($prepared->payload, 0, -1), substr($signed->content, 0, strlen($prepared->payload) - 1), 'signing only appends the signatures');
    }

    #[Test]
    public function a_hand_built_document_keeps_its_number_text(): void
    {
        $authority = (new FakeEta())->authority();
        $json = '{"internalID":"X-9","totalAmount":14.00,"invoiceLines":[{"quantity":1.50}]}';

        $prepared = $authority->prepareDocument($json);
        $signed = $authority->sign($prepared, FakeEta::key());

        self::assertSame('X-9', $prepared->invoiceNumber);
        self::assertStringContainsString('"totalAmount":14.00,"invoiceLines":[{"quantity":1.50}]', $signed->content);
    }

    #[Test]
    public function submit_logs_in_with_basic_auth_and_returns_accepted_and_rejected_documents(): void
    {
        $eta = (new FakeEta())->token('tok-1')->reply(202, [
            'submissionUUID' => 'SUB0000000000000000000001',
            'acceptedDocuments' => [['uuid' => 'DOC00000000000000000000001', 'longId' => 'LONG1', 'internalId' => 'INV-1']],
            'rejectedDocuments' => [['internalId' => 'INV-2', 'error' => ['code' => 'ValidationError', 'message' => 'Validation Error', 'target' => 'INV-2', 'details' => [
                ['code' => 'CF331', 'message' => 'Value is invalid', 'target' => 'taxpayerActivityCode'],
            ]]]],
        ]);
        $authority = $eta->authority();
        $one = $authority->sign($authority->prepare(FakeEta::invoice('INV-1')), FakeEta::key());
        $two = $authority->sign($authority->prepare(FakeEta::invoice('INV-2')), FakeEta::key());

        $result = $authority->submit($one, $two);

        $login = $eta->requests[0];
        self::assertSame('https://id.preprod.eta.gov.eg/connect/token', (string) $login->getUri());
        self::assertSame('Basic ' . base64_encode('client-1:s3cret'), $login->getHeaderLine('Authorization'));
        self::assertSame('grant_type=client_credentials&scope=InvoicingAPI', $eta->body(0));
        self::assertStringNotContainsString('s3cret', $eta->body(0));

        $submit = $eta->requests[1];
        self::assertSame('POST', $submit->getMethod());
        self::assertSame('https://api.preprod.invoicing.eta.gov.eg/api/v1.0/documentsubmissions', (string) $submit->getUri());
        self::assertSame('Bearer tok-1', $submit->getHeaderLine('Authorization'));
        self::assertSame('{"documents":[' . $one->content . ',' . $two->content . ']}', $eta->body(1));

        self::assertSame('SUB0000000000000000000001', $result->submissionId);
        self::assertSame('DOC00000000000000000000001', $result->accepted('INV-1')?->authorityId);
        $rejected = $result->rejected('INV-2');
        self::assertNotNull($rejected);
        self::assertSame(['Value is invalid', 'CF331', 'taxpayerActivityCode'], [$rejected->errors[0]->message, $rejected->errors[0]->code, $rejected->errors[0]->field]);
        self::assertFalse($result->allAccepted());
    }

    #[Test]
    public function the_token_is_reused_and_renewed_once_on_401(): void
    {
        $eta = (new FakeEta())->token('tok-1')
            ->reply(200, ['status' => 'Valid'])
            ->reply(401)
            ->token('tok-2')
            ->reply(200, ['status' => 'Valid']);
        $authority = $eta->authority();

        $authority->status('DOC1');
        $authority->status('DOC2');

        self::assertCount(5, $eta->requests);
        self::assertSame('Bearer tok-1', $eta->requests[2]->getHeaderLine('Authorization'));
        self::assertSame('Bearer tok-2', $eta->requests[4]->getHeaderLine('Authorization'));
    }

    #[Test]
    public function an_intermediary_logs_in_on_behalf_of_the_taxpayer(): void
    {
        $eta = (new FakeEta())->token()->reply(200, ['status' => 'Valid']);

        (new EtaFactory())->create($eta->config(['on_behalf_of' => '100000001']))->status('DOC1');

        self::assertSame('100000001', $eta->requests[0]->getHeaderLine('onbehalfof'));
    }

    #[Test]
    public function a_duplicate_submission_is_an_authority_error_with_the_retry_time(): void
    {
        $eta = (new FakeEta())->token()->reply(422, ['error' => ['code' => 'DuplicateSubmission', 'message' => 'Duplicate submission']], ['Retry-After' => '120']);
        $authority = $eta->authority();
        $signed = $authority->sign($authority->prepare(FakeEta::invoice()), FakeEta::key());

        try {
            $authority->submit($signed);
            self::fail('Expected AuthorityError');
        } catch (AuthorityError $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertSame('DuplicateSubmission', $e->authorityCode);
            self::assertStringContainsString('Retry after 120 seconds', $e->getMessage());
        }
    }

    #[Test]
    public function a_refused_login_never_shows_the_secret(): void
    {
        $eta = (new FakeEta())->reply(400, ['error' => 'invalid_client']);

        try {
            $eta->authority()->status('DOC1');
            self::fail('Expected AuthorityError');
        } catch (AuthorityError $e) {
            self::assertSame('invalid_client', $e->authorityCode);
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }

    #[Test]
    public function status_maps_eta_states(): void
    {
        $eta = (new FakeEta())->token();
        foreach (['Submitted', 'Valid', 'Invalid', 'Rejected', 'Cancelled'] as $status) {
            $eta->reply(200, ['uuid' => 'DOC1', 'status' => $status]);
        }
        $authority = $eta->authority();

        $states = array_map(fn () => $authority->status('DOC1'), range(1, 5));

        self::assertSame([DocumentStatus::Pending, DocumentStatus::Accepted, DocumentStatus::Rejected, DocumentStatus::Rejected, DocumentStatus::Cancelled], $states);
        self::assertSame('https://api.preprod.invoicing.eta.gov.eg/api/v1.0/documents/DOC1/details', (string) $eta->requests[1]->getUri());
    }

    #[Test]
    public function cancel_reject_and_decline_change_the_document_state(): void
    {
        $eta = (new FakeEta())->token()->reply(200)->reply(200)->reply(200)->reply(200);
        $authority = $eta->authority();

        $authority->cancel('DOC1', 'Wrong buyer');
        $authority->reject('DOC2', 'Not ordered');
        $authority->declineCancellation('DOC3');
        $authority->declineRejection('DOC4');

        self::assertSame(['PUT', '/api/v1.0/documents/state/DOC1/state'], [$eta->requests[1]->getMethod(), $eta->requests[1]->getUri()->getPath()]);
        self::assertSame('{"status":"cancelled","reason":"Wrong buyer"}', $eta->body(1));
        self::assertSame('{"status":"rejected","reason":"Not ordered"}', $eta->body(2));
        self::assertSame('/api/v1.0/documents/state/DOC3/decline/cancelation', $eta->requests[3]->getUri()->getPath());
        self::assertSame('', $eta->body(3));
        self::assertSame('/api/v1.0/documents/state/DOC4/decline/rejection', $eta->requests[4]->getUri()->getPath());
    }

    #[Test]
    public function an_operation_period_over_error_is_thrown(): void
    {
        $eta = (new FakeEta())->token()->reply(400, ['error' => ['code' => 'OperationPeriodOver', 'message' => 'The operation period is over']]);

        $this->expectException(AuthorityError::class);
        $this->expectExceptionMessage('OperationPeriodOver');

        $eta->authority()->cancel('DOC1', 'late');
    }

    #[Test]
    public function the_builder_refuses_what_it_cannot_compute_exactly(): void
    {
        $authority = (new FakeEta())->authority();
        $usd = Money::of('10.00', 'USD');
        $egp = Money::of('10.00', 'EGP');
        $invoice = fn (Line $line, string $currency = 'EGP') => new Invoice('X', new \DateTimeImmutable(), FakeEta::seller(), FakeEta::buyer(), [$line], $currency);

        $cases = [
            'foreign currency' => [$invoice(new Line('A', '1', $usd, [], null, 'EG-1'), 'USD'), 'EGP only'],
            'no subtype' => [$invoice(new Line('A', '1', $egp, [Tax::percent('T1', '14', $egp)], null, 'EG-1')), 'T1 subtype'],
            'table tax' => [$invoice(new Line('A', '1', $egp, [Tax::percent('T2', '5', $egp, exemptionCode: 'Tbl01')], null, 'EG-1')), 'VAT (T1) only'],
            'no item code' => [$invoice(new Line('A', '1', $egp)), 'item code'],
        ];
        foreach ($cases as $name => [$bad, $message]) {
            try {
                $authority->prepare($bad);
                self::fail("Expected InvalidInvoice for {$name}");
            } catch (InvalidInvoice $e) {
                self::assertStringContainsString($message, $e->getMessage(), $name);
            }
        }
    }

    #[Test]
    public function the_factory_needs_credentials_and_an_activity_code(): void
    {
        $this->expectException(AuthorityNotConfigured::class);
        $this->expectExceptionMessage('client_secret');

        (new EtaFactory())->create((new FakeEta())->config(['client_secret' => '']));
    }

    /** @return array<string, mixed> */
    private static function document(string $json): array
    {
        $document = Json::decode($json);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, string>
     */
    private static function amounts(array $line): array
    {
        return [
            'salesTotal' => (string) $line['salesTotal'],
            'discount' => (string) $line['discount']['amount'],
            'netTotal' => (string) $line['netTotal'],
            'tax' => (string) $line['taxableItems'][0]['amount'],
            'total' => (string) $line['total'],
            'amountEGP' => (string) $line['unitValue']['amountEGP'],
        ];
    }
}

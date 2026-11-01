<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Tests\Simulation;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Eta\Json\Json;
use HamadaEmamTech\Einvoice\Eta\Signing\Canonical;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\FakeEta;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\OpenSsl;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\KeySource\Agent\Tests\Fixtures\FakeAgent;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The whole Egypt ETA day of a taxpayer whose key is on a USB token, with the ETA API and the
 * Signing Agent both simulated: log in, sign through the agent, submit, poll the status, cancel.
 * The live versions of these steps need ETA preprod credentials and a real token.
 */
final class EtaThroughSigningAgentTest extends TestCase
{
    #[Test]
    public function an_invoice_signed_on_the_token_is_submitted_accepted_and_cancelled(): void
    {
        $agent = new FakeAgent();
        $eta = (new FakeEta())->token('tok-1')
            ->reply(202, [
                'submissionUUID' => 'SUB0000000000000000000001',
                'acceptedDocuments' => [['uuid' => 'DOC00000000000000000000001', 'longId' => 'LONG1', 'internalId' => 'INV-1']],
                'rejectedDocuments' => [],
            ])
            ->reply(200, ['uuid' => 'DOC00000000000000000000001', 'status' => 'Submitted'])
            ->reply(200, ['uuid' => 'DOC00000000000000000000001', 'status' => 'Valid'])
            ->reply(200);
        $authority = $eta->authority();
        $prepared = $authority->prepare(FakeEta::invoice('INV-1'));

        $signed = $authority->sign($prepared, $agent->key());
        $result = $authority->submit($signed);
        $states = [$authority->status('DOC00000000000000000000001'), $authority->status('DOC00000000000000000000001')];
        $authority->cancel('DOC00000000000000000000001', 'Wrong buyer');

        // The agent got only the bytes to sign, never the invoice.
        $signs = array_values(array_filter($agent->requests, fn ($r) => $r->getUri()->getPath() === '/sign'));
        self::assertCount(1, $signs);
        $asked = json_decode((string) $signs[0]->getBody(), true);
        self::assertIsArray($asked);
        self::assertSame(['certificate', 'algorithm', 'data'], array_keys($asked));
        self::assertSame(['main:01', 'rsa-sha256'], [$asked['certificate'], $asked['algorithm']]);
        self::assertStringNotContainsString('INV-1', (string) base64_decode((string) $asked['data'], true));

        // ETA got a CAdES-BES that OpenSSL accepts, over the canonical text, with the token's certificate.
        $document = Json::decode($signed->content);
        self::assertIsArray($document);
        $cms = (string) base64_decode($document['signatures'][0]['value'], true);
        self::assertTrue(OpenSsl::verifies($cms, Canonical::serialize($document)));
        [, $printed] = OpenSsl::cms($cms, '', ['cms', '-cmsout', '-print', '-noout']);
        self::assertStringContainsString('CN=Test Signer', $printed);

        self::assertTrue($result->allAccepted());
        self::assertSame('DOC00000000000000000000001', $result->accepted('INV-1')?->authorityId);
        self::assertSame([DocumentStatus::Pending, DocumentStatus::Accepted], $states);
        self::assertSame('{"status":"cancelled","reason":"Wrong buyer"}', $eta->body(4));
        self::assertCount(1, array_filter($eta->requests, fn ($r) => str_contains((string) $r->getUri(), '/connect/token')), 'one login for the whole day');
    }

    #[Test]
    public function switching_from_a_key_file_to_the_token_changes_no_application_code(): void
    {
        foreach ([FakeEta::key(), (new FakeAgent())->key()] as $key) {
            self::assertTrue(OpenSsl::verifies(...self::signInvoice($key)), $key->name());
        }
    }

    #[Test]
    public function a_token_pulled_out_fails_the_signing_and_nothing_reaches_eta(): void
    {
        $agent = (new FakeAgent())->reply(503, ['error' => ['code' => 'token_not_present', 'message' => 'Token "main" is not connected. Plug it in and try again.']]);
        $eta = new FakeEta();
        $authority = $eta->authority();
        $prepared = $authority->prepare(FakeEta::invoice());

        try {
            $authority->sign($prepared, $agent->key());
            self::fail('signing should fail');
        } catch (SigningError $e) {
            self::assertSame('Signing agent: HTTP 503 token_not_present. Token "main" is not connected. Plug it in and try again.', $e->getMessage());
        }
        self::assertSame([], $eta->requests);

        // Plugged back in: the same prepared document signs.
        self::assertTrue(OpenSsl::verifies(...self::signInvoice($agent->key())));
    }

    #[Test]
    public function a_locked_pin_is_reported_with_its_code(): void
    {
        $agent = (new FakeAgent())->reply(503, ['error' => ['code' => 'pin_locked', 'message' => 'Token "main": the PIN is locked.']]);

        $this->expectExceptionMessage('HTTP 503 pin_locked');

        (new FakeEta())->authority()->sign((new FakeEta())->authority()->prepare(FakeEta::invoice()), $agent->key());
    }

    /** @return array{string, string} the CMS and the canonical text it signs */
    private static function signInvoice(KeySource $key): array
    {
        $authority = (new FakeEta())->authority();
        $document = Json::decode($authority->sign($authority->prepare(FakeEta::invoice()), $key)->content);
        self::assertIsArray($document);

        return [(string) base64_decode($document['signatures'][0]['value'], true), Canonical::serialize($document)];
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Document\PreparedDocument;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Result\AcceptedDocument;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Result\Problem;
use HamadaEmamTech\Einvoice\Result\RejectedDocument;
use HamadaEmamTech\Einvoice\Result\SubmissionResult;
use HamadaEmamTech\Einvoice\Zatca\Api\ZatcaApi;
use HamadaEmamTech\Einvoice\Zatca\Signing\Certificate;
use HamadaEmamTech\Einvoice\Zatca\Signing\Stamp;
use HamadaEmamTech\Einvoice\Zatca\Ubl\InvoiceOptions;
use HamadaEmamTech\Einvoice\Zatca\Ubl\UblWriter;

/**
 * ZATCA (Fatoora), Saudi Arabia: UBL 2.1 XML, an ECDSA stamp with a QR code, then
 * clearance (standard, B2B) or reporting (simplified, B2C).
 *
 * submit() picks the endpoint from the document: standard → /invoices/clearance/single,
 * simplified → /invoices/reporting/single. In compliance mode every document goes to
 * /compliance/invoices instead, which is how onboarding checks a new EGS unit.
 */
final class ZatcaAuthority implements TaxAuthority
{
    public function __construct(
        private readonly ZatcaApi $api,
        private readonly ?Csid $csid,
        private readonly bool $compliance = false,
    ) {
    }

    public function name(): string
    {
        return 'zatca';
    }

    public function prepare(Invoice $invoice): PreparedDocument
    {
        $options = InvoiceOptions::of($invoice);
        $uuid = $invoice->uuid ?? self::uuidFor($invoice);

        return new PreparedDocument('zatca', $invoice->number, (new UblWriter())->write($invoice, $options, $uuid), 'application/xml', [
            'uuid' => $uuid,
            'icv' => $options->counter,
            'simplified' => $options->simplified,
        ]);
    }

    public function sign(PreparedDocument $document, KeySource $key): SignedDocument
    {
        $certificate = $key->certificate()
            ?? throw new SigningError("Key source \"{$key->name()}\" has no certificate. Onboard the EGS unit first, then attach the CSID: \$key->withCertificate(\$csid->certificatePem()).");

        $stamp = Stamp::apply($document->payload, $key, Certificate::from($certificate), (bool) ($document->meta['simplified'] ?? true));

        return new SignedDocument('zatca', $document->invoiceNumber, $stamp['xml'], 'application/xml', $stamp['hash'], $document->meta + [
            'qr' => $stamp['qr'],
        ]);
    }

    public function submit(SignedDocument ...$documents): SubmissionResult
    {
        $csid = $this->csid ?? throw new AuthorityNotConfigured('ZATCA needs the CSID to submit: set "certificate" (the binarySecurityToken) and "secret" in its config.');

        $accepted = $rejected = [];
        foreach ($documents as $document) {
            $simplified = (bool) ($document->meta['simplified'] ?? true);
            [$path, $headers] = match (true) {
                $this->compliance => ['/compliance/invoices', []],
                $simplified => ['/invoices/reporting/single', ['Clearance-Status' => '0']],
                default => ['/invoices/clearance/single', ['Clearance-Status' => '1']],
            };

            [$status, $body] = $this->api->post($path, [
                'invoiceHash' => (string) $document->hash,
                'uuid' => (string) ($document->meta['uuid'] ?? ''),
                'invoice' => base64_encode($document->content),
            ], $headers, $csid);

            if ($status === 303) {
                throw new AuthorityError('zatca', 'Clearance is switched off for this taxpayer: ZATCA asks to report standard invoices instead.', 303);
            }

            $validation = is_array($body['validationResults'] ?? null) ? $body['validationResults'] : [];
            $errors = self::problems($validation['errorMessages'] ?? []);
            $outcome = (string) ($body['clearanceStatus'] ?? $body['reportingStatus'] ?? $validation['status'] ?? '');

            if ($status >= 400 || $errors !== [] || str_starts_with($outcome, 'NOT_') || $outcome === 'ERROR') {
                $rejected[] = new RejectedDocument($document->invoiceNumber, $errors ?: [new Problem("ZATCA answered HTTP {$status} {$outcome}.")], $body);
                continue;
            }

            $cleared = is_string($body['clearedInvoice'] ?? null) ? base64_decode($body['clearedInvoice'], true) : false;
            $accepted[] = new AcceptedDocument(
                $document->invoiceNumber,
                (string) ($document->meta['uuid'] ?? '') ?: null,
                $cleared === false ? null : $cleared,
                self::problems($validation['warningMessages'] ?? []),
                $body,
            );
        }

        return new SubmissionResult('zatca', $accepted, $rejected);
    }

    public function status(string $authorityId): DocumentStatus
    {
        throw new AuthorityError('zatca', 'ZATCA answers every submission at once and has no status endpoint. Keep the SubmissionResult.');
    }

    /** @return list<Problem> */
    private static function problems(mixed $messages): array
    {
        $problems = [];
        foreach (is_array($messages) ? $messages : [] as $message) {
            if (is_array($message)) {
                $problems[] = new Problem(
                    (string) ($message['message'] ?? ''),
                    isset($message['code']) ? (string) $message['code'] : null,
                    isset($message['category']) ? (string) $message['category'] : null,
                );
            }
        }

        return $problems;
    }

    /** A stable UUID (v5 layout) from the seller and the invoice number, so prepare() stays deterministic. */
    private static function uuidFor(Invoice $invoice): string
    {
        $hash = sha1("zatca|{$invoice->seller->taxNumber}|{$invoice->type->value}|{$invoice->number}");
        $hash[12] = '5';
        $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Document\PreparedDocument;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Eta\Api\EtaApi;
use HamadaEmamTech\Einvoice\Eta\Json\Json;
use HamadaEmamTech\Einvoice\Eta\Signing\Cades;
use HamadaEmamTech\Einvoice\Eta\Signing\Canonical;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Result\AcceptedDocument;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Result\Problem;
use HamadaEmamTech\Einvoice\Result\RejectedDocument;
use HamadaEmamTech\Einvoice\Result\SubmissionResult;

/**
 * Egypt's eInvoicing (ETA): a JSON document, its canonical text signed as CAdES-BES with the
 * taxpayer's RSA certificate (usually on a USB token), then a batch submission.
 *
 * ETA validates asynchronously. An accepted document in submit() is accepted for processing:
 * call status() later to get valid (Accepted) or invalid (Rejected).
 */
final class EtaAuthority implements TaxAuthority
{
    public function __construct(
        private readonly EtaApi $api,
        private readonly DocumentBuilder $builder,
    ) {
    }

    public function name(): string
    {
        return 'eta';
    }

    public function prepare(Invoice $invoice): PreparedDocument
    {
        return $this->prepareDocument(Json::encode($this->builder->build($invoice)));
    }

    /**
     * A document you built yourself, as ETA JSON. Write amounts with their decimals as you want
     * them hashed ("14.00"): they are kept exactly as written.
     */
    public function prepareDocument(string $json): PreparedDocument
    {
        $document = Json::decode($json);
        if (! is_array($document) || array_is_list($document)) {
            throw new InvalidInvoice('An ETA document is a JSON object.');
        }
        $internalId = $document['internalID'] ?? $document['internalId'] ?? null;
        if (! is_string($internalId) || $internalId === '') {
            throw new InvalidInvoice('The ETA document has no internalID.');
        }

        return new PreparedDocument('eta', $internalId, $json, 'application/json');
    }

    public function sign(PreparedDocument $document, KeySource $key): SignedDocument
    {
        /** @var array<string, mixed> $decoded prepareDocument() checked it is an object */
        $decoded = Json::decode($document->payload);
        $canonical = Canonical::serialize($decoded);
        $decoded['signatures'] = [['signatureType' => 'I', 'value' => base64_encode(Cades::sign($canonical, $key))]];

        return new SignedDocument('eta', $document->invoiceNumber, Json::encode($decoded), 'application/json', hash('sha256', $canonical), $document->meta);
    }

    public function submit(SignedDocument ...$documents): SubmissionResult
    {
        if ($documents === []) {
            return new SubmissionResult('eta', [], []);
        }
        // Concatenated as stored, never re-encoded: the signed numbers keep their text.
        $body = '{"documents":[' . implode(',', array_map(fn (SignedDocument $d) => $d->content, $documents)) . ']}';
        [, $response] = $this->api->post('/api/v1.0/documentsubmissions', $body);

        $accepted = $rejected = [];
        foreach (self::list($response['acceptedDocuments'] ?? []) as $item) {
            $accepted[] = new AcceptedDocument((string) ($item['internalId'] ?? ''), isset($item['uuid']) ? (string) $item['uuid'] : null, raw: $item);
        }
        foreach (self::list($response['rejectedDocuments'] ?? []) as $item) {
            $rejected[] = new RejectedDocument((string) ($item['internalId'] ?? ''), self::problems(is_array($item['error'] ?? null) ? $item['error'] : []), $item);
        }

        return new SubmissionResult('eta', $accepted, $rejected, isset($response['submissionUUID']) ? (string) $response['submissionUUID'] : null);
    }

    public function status(string $authorityId): DocumentStatus
    {
        $status = strtolower((string) ($this->details($authorityId)['status'] ?? ''));

        return match ($status) {
            'submitted' => DocumentStatus::Pending,
            'valid' => DocumentStatus::Accepted,
            'invalid', 'rejected' => DocumentStatus::Rejected,
            'cancelled' => DocumentStatus::Cancelled,
            default => throw new AuthorityError('eta', "Document {$authorityId} has an unknown status \"{$status}\"."),
        };
    }

    /**
     * The document with its status and validation results ("validationResults").
     *
     * @return array<string, mixed>
     */
    public function details(string $uuid): array
    {
        return $this->api->get('/api/v1.0/documents/' . rawurlencode($uuid) . '/details')[1];
    }

    /**
     * One submission and the status of its documents ("overallStatus": in progress, valid, partially valid, invalid).
     *
     * @return array<string, mixed>
     */
    public function submission(string $submissionId, int $page = 1, int $pageSize = 100): array
    {
        return $this->api->get('/api/v1.0/documentSubmissions/' . rawurlencode($submissionId) . "?pageNo={$page}&pageSize={$pageSize}")[1];
    }

    /** The issuer cancels a valid document. ETA limits how long after issuing this is allowed. */
    public function cancel(string $uuid, string $reason): void
    {
        $this->changeState($uuid, 'cancelled', $reason);
    }

    /** The receiver rejects a valid document it received. */
    public function reject(string $uuid, string $reason): void
    {
        $this->changeState($uuid, 'rejected', $reason);
    }

    /** The receiver declines the issuer's cancellation. */
    public function declineCancellation(string $uuid): void
    {
        $this->api->put('/api/v1.0/documents/state/' . rawurlencode($uuid) . '/decline/cancelation');
    }

    /** The issuer declines the receiver's rejection. */
    public function declineRejection(string $uuid): void
    {
        $this->api->put('/api/v1.0/documents/state/' . rawurlencode($uuid) . '/decline/rejection');
    }

    /** The document's printout as PDF bytes. */
    public function printout(string $uuid): string
    {
        return $this->api->download('/api/v1/documents/' . rawurlencode($uuid) . '/pdf');
    }

    private function changeState(string $uuid, string $status, string $reason): void
    {
        $this->api->put('/api/v1.0/documents/state/' . rawurlencode($uuid) . '/state', Json::encode(['status' => $status, 'reason' => $reason]));
    }

    /**
     * @param array<mixed> $error ETA's Error: code, message, target, details[]
     *
     * @return list<Problem>
     */
    private static function problems(array $error): array
    {
        $problems = [];
        foreach (self::list($error['details'] ?? []) as $detail) {
            $problems[] = self::problem($detail);
        }

        return $problems !== [] ? $problems : [self::problem($error)];
    }

    /** @param array<mixed> $error */
    private static function problem(array $error): Problem
    {
        return new Problem(
            is_string($error['message'] ?? null) ? $error['message'] : 'Rejected without a message.',
            isset($error['code']) ? (string) $error['code'] : null,
            is_string($error['target'] ?? null) ? $error['target'] : null,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}

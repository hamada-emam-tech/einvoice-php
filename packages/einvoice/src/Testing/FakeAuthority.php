<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Testing;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Document\PreparedDocument;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Party;
use HamadaEmamTech\Einvoice\Model\Tax;
use HamadaEmamTech\Einvoice\Result\AcceptedDocument;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Result\Problem;
use HamadaEmamTech\Einvoice\Result\RejectedDocument;
use HamadaEmamTech\Einvoice\Result\SubmissionResult;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use PHPUnit\Framework\Assert;

/**
 * In-memory authority for your tests. Always available as "fake", no install needed.
 * It prepares canonical JSON, signs with whatever key you give it, and accepts
 * everything unless you tell it to reject.
 *
 *     $fake = $einvoice->authority('fake');
 *     $fake->reject('INV-2', 'Buyer VAT number is invalid');
 *     $result = $einvoice->issue($invoice);
 *     $fake->assertSubmitted(fn (SignedDocument $d) => $d->invoiceNumber === 'INV-1');
 */
final class FakeAuthority implements TaxAuthority
{
    /** @var list<SignedDocument> */
    private array $submitted = [];

    /** @var array<string, string> invoice number → rejection message */
    private array $rejections = [];

    /** @var array<string, DocumentStatus> */
    private array $statuses = [];

    private int $sequence = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function prepare(Invoice $invoice): PreparedDocument
    {
        $payload = json_encode(self::sorted([
            'number' => $invoice->number,
            'type' => $invoice->type->value,
            'issuedAt' => $invoice->issuedAt->format(DATE_ATOM),
            'currency' => $invoice->currency,
            'seller' => self::party($invoice->seller),
            'buyer' => $invoice->buyer === null ? null : self::party($invoice->buyer),
            'lines' => array_map(fn (Line $l) => [
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unitPrice' => $l->unitPrice->toDecimal(),
                'net' => $l->net->toDecimal(),
                'taxes' => array_map(fn (Tax $t) => ['category' => $t->category, 'rate' => $t->rate, 'amount' => $t->amount->toDecimal()], $l->taxes),
            ], $invoice->lines),
            'totals' => [
                'net' => $invoice->netTotal()->toDecimal(),
                'tax' => $invoice->taxTotal()->toDecimal(),
                'gross' => $invoice->grossTotal()->toDecimal(),
            ],
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new PreparedDocument('fake', $invoice->number, $payload, 'application/json');
    }

    public function sign(PreparedDocument $document, KeySource $key): SignedDocument
    {
        $format = $key->supports(SignatureFormat::RawEcdsa) ? SignatureFormat::RawEcdsa : SignatureFormat::RawRsa;
        if (! $key->supports($format)) {
            throw SigningError::unsupported($key->name(), $format);
        }
        $signature = $key->sign($document->payload, $format);

        return new SignedDocument(
            'fake',
            $document->invoiceNumber,
            $document->payload,
            $document->contentType,
            base64_encode(hash('sha256', $document->payload, true)),
            ['signature' => base64_encode($signature), 'format' => $format->value],
        );
    }

    public function submit(SignedDocument ...$documents): SubmissionResult
    {
        $accepted = $rejected = [];
        foreach ($documents as $document) {
            $this->submitted[] = $document;
            if (isset($this->rejections[$document->invoiceNumber])) {
                $rejected[] = new RejectedDocument($document->invoiceNumber, [new Problem($this->rejections[$document->invoiceNumber], 'FAKE-REJECTED')]);
                continue;
            }
            $id = 'fake-' . ++$this->sequence;
            $this->statuses[$id] = DocumentStatus::Accepted;
            $accepted[] = new AcceptedDocument($document->invoiceNumber, $id);
        }

        return new SubmissionResult('fake', $accepted, $rejected, 'fake-submission-' . count($this->submitted));
    }

    public function status(string $authorityId): DocumentStatus
    {
        return $this->statuses[$authorityId] ?? throw new AuthorityError('fake', "Unknown document {$authorityId}.", 404);
    }

    // ---- test helpers ----------------------------------------------------------

    /** Reject this invoice number on every later submission. */
    public function reject(string $invoiceNumber, string $message = 'Rejected by the fake authority.'): self
    {
        $this->rejections[$invoiceNumber] = $message;

        return $this;
    }

    public function cancel(string $authorityId): void
    {
        $this->status($authorityId);
        $this->statuses[$authorityId] = DocumentStatus::Cancelled;
    }

    /** @param (callable(SignedDocument): bool)|null $matching */
    public function assertSubmitted(?callable $matching = null): void
    {
        $hits = array_filter($this->submitted, fn (SignedDocument $d) => $matching === null || $matching($d));
        Assert::assertNotEmpty($hits, 'No matching document was submitted to the fake authority.');
    }

    public function assertNothingSubmitted(): void
    {
        Assert::assertSame([], $this->submitted, 'Documents were submitted to the fake authority.');
    }

    /** @return array<string, mixed> */
    private static function party(Party $party): array
    {
        return ['name' => $party->name, 'taxNumber' => $party->taxNumber, 'identifiers' => $party->identifiers];
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::sorted($v) : $v, $value);
    }
}

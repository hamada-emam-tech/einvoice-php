<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Result;

/**
 * What the authority said about one submission. Partial success is normal:
 * ETA answers one batch with accepted and rejected documents together. Check both lists.
 */
final class SubmissionResult
{
    /**
     * @param list<AcceptedDocument> $accepted
     * @param list<RejectedDocument> $rejected
     */
    public function __construct(
        public readonly string $authority,
        public readonly array $accepted,
        public readonly array $rejected,
        public readonly ?string $submissionId = null,
    ) {
    }

    public function allAccepted(): bool
    {
        return $this->rejected === [];
    }

    public function accepted(string $invoiceNumber): ?AcceptedDocument
    {
        foreach ($this->accepted as $document) {
            if ($document->invoiceNumber === $invoiceNumber) {
                return $document;
            }
        }

        return null;
    }

    public function rejected(string $invoiceNumber): ?RejectedDocument
    {
        foreach ($this->rejected as $document) {
            if ($document->invoiceNumber === $invoiceNumber) {
                return $document;
            }
        }

        return null;
    }
}

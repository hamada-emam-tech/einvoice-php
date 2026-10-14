<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Document;

/**
 * A signed invoice, ready to submit. Store it before submitting: if the call fails or times out,
 * resubmit the same document instead of signing again (ZATCA chains each invoice to the previous hash).
 */
final class SignedDocument
{
    /** @param array<string, mixed> $meta authority-specific results, e.g. 'qr' for ZATCA */
    public function __construct(
        public readonly string $authority,
        public readonly string $invoiceNumber,
        public readonly string $content,
        public readonly string $contentType,
        public readonly ?string $hash = null,
        public readonly array $meta = [],
    ) {
    }
}

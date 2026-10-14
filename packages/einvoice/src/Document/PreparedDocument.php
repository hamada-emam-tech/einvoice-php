<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Document;

/**
 * An invoice in the authority's own format, canonicalized and ready to sign.
 * $payload is exact bytes: never re-encode or pretty-print it, or the signature breaks.
 */
final class PreparedDocument
{
    /** @param array<string, mixed> $meta authority-specific values computed while preparing (UUID, counter, previous hash...) */
    public function __construct(
        public readonly string $authority,
        public readonly string $invoiceNumber,
        public readonly string $payload,
        public readonly string $contentType,
        public readonly array $meta = [],
    ) {
    }
}

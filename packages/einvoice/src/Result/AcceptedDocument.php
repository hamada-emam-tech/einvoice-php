<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Result;

final class AcceptedDocument
{
    /**
     * @param string|null          $authorityId the ID the authority gave it (ETA UUID...), when it gives one
     * @param string|null          $content     the document as the authority returned it (ZATCA cleared XML), when it changes it
     * @param list<Problem>        $warnings    accepted, but the authority flagged something
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly ?string $authorityId = null,
        public readonly ?string $content = null,
        public readonly array $warnings = [],
        public readonly array $raw = [],
    ) {
    }
}

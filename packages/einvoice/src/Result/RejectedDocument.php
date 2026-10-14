<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Result;

final class RejectedDocument
{
    /**
     * @param list<Problem>        $errors
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly array $errors,
        public readonly array $raw = [],
    ) {
    }
}

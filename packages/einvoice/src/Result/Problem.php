<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Result;

/** One error or warning from the authority, as close to its own wording as possible. */
final class Problem
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $code = null,
        public readonly ?string $field = null,
    ) {
    }
}

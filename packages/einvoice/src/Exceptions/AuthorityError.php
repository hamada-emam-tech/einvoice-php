<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Exceptions;

use Throwable;

/**
 * The authority's API failed as a whole (auth, outage, malformed request).
 * A document the authority rejected is not an exception: it comes back in SubmissionResult::$rejected.
 */
final class AuthorityError extends EinvoiceException
{
    public function __construct(
        public readonly string $authority,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $authorityCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$authority}] {$message}", 0, $previous);
    }
}

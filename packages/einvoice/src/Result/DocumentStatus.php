<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Result;

enum DocumentStatus: string
{
    /** Received, validation not finished (ETA validates asynchronously). */
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    /** Cancelled by the issuer after acceptance. */
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}

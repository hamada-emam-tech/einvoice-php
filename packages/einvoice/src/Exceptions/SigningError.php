<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Exceptions;

use HamadaEmamTech\Einvoice\Signing\SignatureFormat;

/** The key source could not sign: wrong key type, unsupported format, token not reachable. Never carries key material or PINs. */
final class SigningError extends EinvoiceException
{
    public static function unsupported(string $keySource, SignatureFormat $format): self
    {
        return new self(sprintf('Key source "%s" cannot produce a %s signature.', $keySource, $format->value));
    }
}

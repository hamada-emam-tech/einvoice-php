<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Exceptions;

final class AuthorityNotConfigured extends EinvoiceException
{
    public static function named(string $authority): self
    {
        return new self(sprintf('Authority "%s" is installed but has no entry under "authorities" in the Einvoice config.', $authority));
    }

    public static function noDefault(): self
    {
        return new self('No authority name given and no "default" authority in the Einvoice config.');
    }

    public static function noKey(string $authority): self
    {
        return new self(sprintf('Authority "%s" has no "key" in the Einvoice config and none was passed in.', $authority));
    }
}

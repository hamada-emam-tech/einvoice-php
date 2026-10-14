<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Exceptions;

final class AuthorityNotInstalled extends EinvoiceException
{
    public static function named(string $authority): self
    {
        return new self(sprintf(
            'Authority "%s" is not installed. Run: composer require hamada-emam-tech/einvoice-%s',
            $authority,
            $authority,
        ));
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Testing;

use HamadaEmamTech\Einvoice\Contracts\AuthorityFactory;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;

final class FakeAuthorityFactory implements AuthorityFactory
{
    public function create(array $config): TaxAuthority
    {
        return new FakeAuthority();
    }
}

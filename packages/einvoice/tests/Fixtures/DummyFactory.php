<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Tests\Fixtures;

use HamadaEmamTech\Einvoice\Contracts\AuthorityFactory;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Testing\FakeAuthority;

/** Stands in for a real authority package's factory in discovery tests. */
final class DummyFactory implements AuthorityFactory
{
    /** @var array<string, mixed> */
    public static array $lastConfig = [];

    public function create(array $config): TaxAuthority
    {
        self::$lastConfig = $config;

        return new FakeAuthority();
    }
}

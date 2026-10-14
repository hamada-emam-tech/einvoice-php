<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Discovery;

use Composer\Autoload\ClassLoader;
use HamadaEmamTech\Einvoice\Contracts\AuthorityFactory;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotInstalled;
use HamadaEmamTech\Einvoice\Exceptions\EinvoiceException;
use HamadaEmamTech\Einvoice\Testing\FakeAuthorityFactory;
use ReflectionClass;

/**
 * Knows which authorities are installed.
 *
 * Authority packages declare their factory in composer.json under extra.einvoice.authorities.
 * The registry reads Composer's vendor/composer/installed.json once, the same way
 * Laravel package discovery works, so `composer require hamada-emam-tech/einvoice-zatca`
 * is all it takes. Anything can also be registered by hand with register().
 */
final class AuthorityRegistry
{
    /** @var array<string, class-string<AuthorityFactory>|AuthorityFactory|callable(array<string, mixed>): TaxAuthority> */
    private array $factories = ['fake' => FakeAuthorityFactory::class];

    private bool $discovered = false;

    public function __construct(private readonly ?string $installedJsonPath = null)
    {
    }

    /** @param class-string<AuthorityFactory>|AuthorityFactory|callable(array<string, mixed>): TaxAuthority $factory */
    public function register(string $name, string|AuthorityFactory|callable $factory): void
    {
        $this->factories[strtolower($name)] = $factory;
    }

    public function has(string $name): bool
    {
        $this->discover();

        return isset($this->factories[strtolower($name)]);
    }

    /** @return list<string> */
    public function names(): array
    {
        $this->discover();

        return array_keys($this->factories);
    }

    /** @param array<string, mixed> $config */
    public function create(string $name, array $config): TaxAuthority
    {
        $this->discover();
        $factory = $this->factories[strtolower($name)] ?? throw AuthorityNotInstalled::named($name);

        if (is_string($factory)) {
            if (! is_subclass_of($factory, AuthorityFactory::class)) {
                throw new EinvoiceException("Factory {$factory} for authority \"{$name}\" must implement " . AuthorityFactory::class . '.');
            }
            $factory = new $factory();
        }

        return $factory instanceof AuthorityFactory ? $factory->create($config) : $factory($config);
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        $path = $this->installedJsonPath ?? self::defaultInstalledJsonPath();
        if ($path === null || ! is_file($path)) {
            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $packages = $data['packages'] ?? $data ?? [];   // Composer 2 wraps the list in "packages"

        foreach ($packages as $package) {
            foreach ($package['extra']['einvoice']['authorities'] ?? [] as $name => $factoryClass) {
                // Explicit registrations win over discovered ones.
                $this->factories[strtolower((string) $name)] ??= $factoryClass;
            }
        }
    }

    private static function defaultInstalledJsonPath(): ?string
    {
        if (! class_exists(ClassLoader::class)) {
            return null;
        }
        $vendorDir = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);

        return $vendorDir . '/composer/installed.json';
    }
}

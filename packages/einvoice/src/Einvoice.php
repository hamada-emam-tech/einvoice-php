<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice;

use HamadaEmamTech\Einvoice\Contracts\AuthorityFactory;
use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Discovery\AuthorityRegistry;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotInstalled;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Result\SubmissionResult;
use HamadaEmamTech\Einvoice\Testing\FakeKeySource;

/**
 * Entry point. Holds the config, builds authorities on first use, and runs
 * prepare → sign → submit against the default authority.
 *
 *     $einvoice = new Einvoice([
 *         'default'     => 'zatca',
 *         'authorities' => ['zatca' => [..., 'key' => SoftwareKey::fromFile('egs.key', $passphrase)]],
 *     ]);
 *     $signed = $einvoice->sign($invoice);      // store it, then submit, ideally from a queue
 *     $result = $einvoice->submit($signed);
 *
 * "key" is a KeySource, or a callable that returns one so keys are only loaded when used.
 * A key passed to sign() wins over the configured one: that is how one app signs for many tenants.
 */
final class Einvoice
{
    /** @var array<string, TaxAuthority> */
    private array $built = [];

    private readonly AuthorityRegistry $registry;

    /** @param array{default?: string, authorities?: array<string, array<string, mixed>>} $config */
    public function __construct(private readonly array $config = [], ?AuthorityRegistry $registry = null)
    {
        $this->registry = $registry ?? new AuthorityRegistry();
    }

    /** The default authority, or the one you name. Built once, then reused. */
    public function authority(?string $name = null): TaxAuthority
    {
        $name = $this->resolveName($name);

        if (! isset($this->built[$name])) {
            if (! $this->registry->has($name)) {
                throw AuthorityNotInstalled::named($name);
            }
            $authorityConfig = $this->authorityConfig($name);
            unset($authorityConfig['key']);
            $this->built[$name] = $this->registry->create($name, $authorityConfig);
        }

        return $this->built[$name];
    }

    /** The key configured for an authority. */
    public function key(?string $authority = null): KeySource
    {
        $name = $this->resolveName($authority);
        $key = $this->authorityConfig($name)['key'] ?? ($name === 'fake' ? new FakeKeySource() : throw AuthorityNotConfigured::noKey($name));
        $key = is_callable($key) ? $key() : $key;

        return $key instanceof KeySource ? $key : throw AuthorityNotConfigured::noKey($name);
    }

    /**
     * Register an authority by hand, e.g. one you wrote yourself.
     *
     * @param class-string<AuthorityFactory>|AuthorityFactory|callable(array<string, mixed>): TaxAuthority $factory
     */
    public function extend(string $name, string|AuthorityFactory|callable $factory): self
    {
        $this->registry->register($name, $factory);
        unset($this->built[strtolower($name)]);

        return $this;
    }

    /** @return list<string> every authority that is installed or registered */
    public function installed(): array
    {
        return $this->registry->names();
    }

    /** Prepare and sign. Store the result before submitting it. */
    public function sign(Invoice $invoice, ?KeySource $key = null, ?string $authority = null): SignedDocument
    {
        $tax = $this->authority($authority);

        return $tax->sign($tax->prepare($invoice), $key ?? $this->key($authority));
    }

    public function submit(SignedDocument ...$documents): SubmissionResult
    {
        $authority = $documents === [] ? null : $documents[0]->authority;
        foreach ($documents as $document) {
            if ($document->authority !== $authority) {
                throw new Exceptions\EinvoiceException('Submit documents for one authority at a time.');
            }
        }

        return $this->authority($authority)->submit(...$documents);
    }

    /** Prepare, sign and submit in one call. Fine for scripts; in a POS, sign now and submit from a queue. */
    public function issue(Invoice $invoice, ?KeySource $key = null, ?string $authority = null): SubmissionResult
    {
        return $this->submit($this->sign($invoice, $key, $authority));
    }

    public function status(string $authorityId, ?string $authority = null): DocumentStatus
    {
        return $this->authority($authority)->status($authorityId);
    }

    private function resolveName(?string $name): string
    {
        return strtolower($name ?? $this->config['default'] ?? throw AuthorityNotConfigured::noDefault());
    }

    /** @return array<string, mixed> */
    private function authorityConfig(string $name): array
    {
        return $this->config['authorities'][$name] ?? ($name === 'fake' ? [] : throw AuthorityNotConfigured::named($name));
    }
}

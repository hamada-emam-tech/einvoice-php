<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca;

use HamadaEmamTech\Einvoice\Contracts\AuthorityFactory;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;
use HamadaEmamTech\Einvoice\Zatca\Api\ZatcaApi;
use HamadaEmamTech\Einvoice\Zatca\Support\Http;

/**
 * Builds the ZATCA authority from its config block:
 *
 *     'zatca' => [
 *         'environment' => 'sandbox',           // 'simulation' or 'production', or set 'base_url'
 *         'certificate' => $productionCsid->binarySecurityToken,
 *         'secret'      => $productionCsid->secret(),
 *         'key'         => fn () => SoftwareKey::fromFile('egs.key', $passphrase)->withCertificate($certPem),
 *         // optional: 'compliance' => true (send to /compliance/invoices, for onboarding checks),
 *         //           http_client, request_factory, stream_factory
 *     ]
 */
final class ZatcaFactory implements AuthorityFactory
{
    public function create(array $config): TaxAuthority
    {
        $certificate = $config['certificate'] ?? null;
        $secret = $config['secret'] ?? null;
        $csid = is_string($certificate) && $certificate !== '' && is_string($secret) && $secret !== ''
            ? new Csid($certificate, $secret)
            : null;

        return new ZatcaAuthority(self::api($config), $csid, (bool) ($config['compliance'] ?? false));
    }

    /** @param array<string, mixed> $config */
    public static function api(array $config): ZatcaApi
    {
        [$client, $requests, $streams] = Http::from($config);

        return new ZatcaApi($client, $requests, $streams, rtrim((string) ($config['base_url'] ?? self::environment($config)->baseUrl()), '/'));
    }

    /** @param array<string, mixed> $config */
    public static function environment(array $config): Environment
    {
        $name = (string) ($config['environment'] ?? 'sandbox');

        return Environment::tryFrom($name)
            ?? throw new AuthorityNotConfigured("ZATCA environment must be \"sandbox\", \"simulation\" or \"production\", got \"{$name}\".");
    }
}

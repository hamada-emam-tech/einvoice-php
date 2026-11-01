<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta;

use HamadaEmamTech\Einvoice\Contracts\AuthorityFactory;
use HamadaEmamTech\Einvoice\Contracts\TaxAuthority;
use HamadaEmamTech\Einvoice\Eta\Api\EtaApi;
use HamadaEmamTech\Einvoice\Eta\Support\Http;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityNotConfigured;

/**
 * Builds the ETA authority from its config block:
 *
 *     'eta' => [
 *         'environment'   => 'preprod',          // or 'production'
 *         'client_id'     => env('ETA_CLIENT_ID'),
 *         'client_secret' => env('ETA_CLIENT_SECRET'),
 *         'activity_code' => '4620',             // the taxpayer activity code registered with ETA
 *         'key'           => fn () => $usbTokenKeySource,
 *         // optional: 'on_behalf_of' => '<taxpayer registration number>' (intermediaries),
 *         //           'document_version' => '1.0', http_client, request_factory, stream_factory
 *     ]
 */
final class EtaFactory implements AuthorityFactory
{
    public function create(array $config): TaxAuthority
    {
        [$client, $requests, $streams] = Http::from($config);
        $onBehalfOf = $config['on_behalf_of'] ?? null;

        return new EtaAuthority(
            new EtaApi($client, $requests, $streams, self::environment($config), self::required($config, 'client_id'), self::required($config, 'client_secret'), is_string($onBehalfOf) && $onBehalfOf !== '' ? $onBehalfOf : null),
            new DocumentBuilder(self::required($config, 'activity_code'), (string) ($config['document_version'] ?? '1.0')),
        );
    }

    /** @param array<string, mixed> $config */
    private static function environment(array $config): Environment
    {
        $name = (string) ($config['environment'] ?? 'preprod');

        return Environment::tryFrom($name)
            ?? throw new AuthorityNotConfigured("ETA environment must be \"preprod\" or \"production\", got \"{$name}\".");
    }

    /** @param array<string, mixed> $config */
    private static function required(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new AuthorityNotConfigured("ETA needs \"{$key}\" in its config.");
        }

        return $value;
    }
}

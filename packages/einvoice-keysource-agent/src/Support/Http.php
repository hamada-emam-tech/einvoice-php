<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\KeySource\Agent\Support;

use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** @internal */
final class Http
{
    /**
     * Use the HTTP client you pass, or find one that is installed: php-http/discovery,
     * then Guzzle 7, then Symfony HttpClient.
     *
     * @param array<string, mixed> $config
     *
     * @return array{ClientInterface, RequestFactoryInterface, StreamFactoryInterface}
     */
    public static function from(array $config): array
    {
        $client = $config['http_client'] ?? null;
        $requests = $config['request_factory'] ?? null;
        $streams = $config['stream_factory'] ?? $requests;

        if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
            return [$client, $requests, $streams];
        }

        [$foundClient, $foundRequests, $foundStreams] = self::installed();
        $client = $client instanceof ClientInterface ? $client : $foundClient;
        $requests = $requests instanceof RequestFactoryInterface ? $requests : $foundRequests;
        $streams = $streams instanceof StreamFactoryInterface ? $streams : $foundStreams;

        if ($client instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $streams instanceof StreamFactoryInterface) {
            return [$client, $requests, $streams];
        }

        throw new SigningError('AgentKey needs a PSR-18 HTTP client. Run: composer require guzzlehttp/guzzle (or pass http_client, request_factory and stream_factory).');
    }

    /**
     * The client and factories of an installed library. Names are strings because every one
     * of these libraries is optional.
     *
     * @return array{mixed, mixed, mixed}
     */
    private static function installed(): array
    {
        $discovery = 'Http\\Discovery\\Psr18ClientDiscovery';
        $factories = 'Http\\Discovery\\Psr17FactoryDiscovery';
        if (class_exists($discovery) && class_exists($factories)) {
            return [$discovery::find(), $factories::findRequestFactory(), $factories::findStreamFactory()];
        }

        $guzzle = 'GuzzleHttp\\Client';
        $guzzleFactory = 'GuzzleHttp\\Psr7\\HttpFactory';
        if (class_exists($guzzle) && class_exists($guzzleFactory)) {
            $factory = new $guzzleFactory();

            return [new $guzzle(['timeout' => 30, 'http_errors' => false]), $factory, $factory];
        }

        $symfony = 'Symfony\\Component\\HttpClient\\Psr18Client';
        if (class_exists($symfony)) {
            $client = new $symfony();

            return [$client, $client, $client];
        }

        return [null, null, null];
    }
}

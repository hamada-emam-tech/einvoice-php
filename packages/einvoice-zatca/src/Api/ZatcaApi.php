<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Api;

use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Zatca\Csid;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The Fatoora API over PSR-18. Returns the decoded body and the HTTP status; deciding
 * what a status means is the caller's job. Never puts the CSID secret in an error.
 *
 * @internal
 */
final class ZatcaApi
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, mixed>}
     */
    public function post(string $path, array $body, array $headers = [], ?Csid $credentials = null): array
    {
        $request = $this->requests->createRequest('POST', $this->baseUrl . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept-Language', 'en')
            ->withHeader('Accept-Version', 'V2')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($body, JSON_UNESCAPED_SLASHES)));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($credentials !== null) {
            $request = $request->withHeader('Authorization', $credentials->basic());
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AuthorityError('zatca', "POST {$path} failed: " . $e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status === 401 || $status === 403) {
            throw new AuthorityError('zatca', "POST {$path}: the CSID was refused (HTTP {$status}). Check the certificate, the secret and the environment.", $status);
        }
        if ($status >= 500 || ($status >= 300 && $status < 400 && $status !== 303) || ($status === 400 && $decoded === [])) {
            throw new AuthorityError('zatca', "POST {$path}: HTTP {$status}. " . self::firstMessage($decoded), $status, isset($decoded['code']) ? (string) $decoded['code'] : null);
        }

        return [$status, $decoded];
    }

    /** @param array<string, mixed> $body */
    private static function firstMessage(array $body): string
    {
        foreach (['message', 'dispositionMessage', 'error'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }
        $errors = $body['errors'] ?? null;
        if (is_array($errors) && isset($errors[0])) {
            return is_string($errors[0]) ? $errors[0] : (string) json_encode($errors[0]);
        }

        return '';
    }
}

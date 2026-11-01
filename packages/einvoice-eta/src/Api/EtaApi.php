<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Api;

use HamadaEmamTech\Einvoice\Eta\Environment;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The eInvoicing API over PSR-18. Logs in with the ERP system's client ID and secret
 * (OAuth 2 client credentials, sent as HTTP Basic), keeps the token until a minute before it
 * expires, and logs in again once if the API answers 401.
 *
 * Returns the HTTP status and the decoded body. Whole-call failures (auth, outage, a submission
 * ETA refuses as a whole) throw AuthorityError with ETA's error code. Never puts the secret in an error.
 *
 * @internal
 */
final class EtaApi
{
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Environment $environment,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $onBehalfOf = null,
    ) {
    }

    /** @return array{int, array<string, mixed>} */
    public function get(string $path): array
    {
        return $this->call('GET', $path);
    }

    /** @return array{int, array<string, mixed>} */
    public function post(string $path, string $json): array
    {
        return $this->call('POST', $path, $json);
    }

    /** @return array{int, array<string, mixed>} */
    public function put(string $path, ?string $json = null): array
    {
        return $this->call('PUT', $path, $json);
    }

    /** The raw bytes of a GET (a PDF printout). */
    public function download(string $path): string
    {
        $response = $this->authorized('GET', $path);
        $this->check('GET', $path, $response->getStatusCode(), self::decode($response), $response);

        return (string) $response->getBody();
    }

    /** @return array{int, array<string, mixed>} */
    private function call(string $method, string $path, ?string $json = null): array
    {
        $response = $this->authorized($method, $path, $json);
        $status = $response->getStatusCode();
        $body = self::decode($response);
        $this->check($method, $path, $status, $body, $response);

        return [$status, $body];
    }

    private function authorized(string $method, string $path, ?string $json = null): ResponseInterface
    {
        $build = function () use ($method, $path, $json): RequestInterface {
            $request = $this->requests->createRequest($method, $this->environment->apiUrl() . $path)
                ->withHeader('Authorization', 'Bearer ' . $this->token())
                ->withHeader('Accept', 'application/json')
                ->withHeader('Accept-Language', 'en');
            if ($json !== null) {
                $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($json));
            }

            return $request;
        };

        $response = $this->send($build(), "{$method} {$path}");
        if ($response->getStatusCode() === 401) {
            $this->token = null;
            $response = $this->send($build(), "{$method} {$path}");
        }

        return $response;
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->expiresAt) {
            return $this->token;
        }

        $form = ['grant_type' => 'client_credentials', 'scope' => 'InvoicingAPI'];
        $request = $this->requests->createRequest('POST', $this->environment->idUrl() . '/connect/token')
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream(http_build_query($form)));
        if ($this->onBehalfOf !== null) {
            $request = $request->withHeader('onbehalfof', $this->onBehalfOf);
        }

        $response = $this->send($request, 'login');
        $body = self::decode($response);
        $token = $body['access_token'] ?? null;
        if ($response->getStatusCode() !== 200 || ! is_string($token) || $token === '') {
            $error = is_string($body['error'] ?? null) ? " ({$body['error']})" : '';
            throw new AuthorityError('eta', "Login refused: HTTP {$response->getStatusCode()}{$error}. Check client_id, client_secret and the environment.", $response->getStatusCode(), is_string($body['error'] ?? null) ? $body['error'] : null);
        }

        $this->token = $token;
        $this->expiresAt = time() + max(0, (int) ($body['expires_in'] ?? 3600) - 60);

        return $token;
    }

    private function send(RequestInterface $request, string $what): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AuthorityError('eta', "{$what} failed: " . $e->getMessage(), previous: $e);
        }
    }

    /** @param array<string, mixed> $body */
    private function check(string $method, string $path, int $status, array $body, ResponseInterface $response): void
    {
        if ($status < 400) {
            return;
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : $body;
        $code = is_string($error['code'] ?? null) ? $error['code'] : (is_string($body['error'] ?? null) ? $body['error'] : null);
        $message = "{$method} {$path}: HTTP {$status}" . ($code !== null ? " {$code}" : '') . '.';
        foreach (self::messages($error) as $detail) {
            $message .= ' ' . $detail;
        }
        if ($status === 401 || $status === 403) {
            $message .= ' Check the ERP credentials, the onbehalfof taxpayer and the system\'s permissions.';
        }
        if ($response->hasHeader('Retry-After')) {
            $message .= ' Retry after ' . $response->getHeaderLine('Retry-After') . ' seconds.';
        }

        throw new AuthorityError('eta', $message, $status, $code);
    }

    /**
     * ETA's error message plus the messages of its details, if any.
     *
     * @param array<mixed> $error
     *
     * @return list<string>
     */
    private static function messages(array $error): array
    {
        $messages = [];
        foreach ([$error, ...(is_array($error['details'] ?? null) ? $error['details'] : [])] as $item) {
            if (is_array($item) && is_string($item['message'] ?? null) && $item['message'] !== '') {
                $messages[] = rtrim($item['message'], '.') . '.';
            }
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    private static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

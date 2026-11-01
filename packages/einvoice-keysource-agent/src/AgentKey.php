<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\KeySource\Agent;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\KeySource\Agent\Support\Http;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A key on a USB token or HSM, used through the Signing Agent that runs next to it.
 * The key never leaves the token: this class sends the bytes to sign and gets the signature back.
 *
 *     $key = AgentKey::connect('http://127.0.0.1:8765', getenv('SIGNING_AGENT_API_KEY'), 'main:01');
 *
 * The certificate ID comes from the agent: `signing-agent certificates` or AgentKey::certificates().
 */
final class AgentKey implements KeySource
{
    /** @var array<string, mixed>|null this certificate, as the agent lists it */
    private ?array $info = null;

    private readonly string $url;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $url,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $certificateId,
    ) {
        $this->url = self::checkUrl($url);
    }

    /**
     * @param array<string, mixed> $http optional http_client, request_factory, stream_factory
     */
    public static function connect(string $url, #[\SensitiveParameter] string $apiKey, string $certificateId, array $http = []): self
    {
        [$client, $requests, $streams] = Http::from($http);

        return new self($client, $requests, $streams, $url, $apiKey, $certificateId);
    }

    /**
     * Every certificate on the agent's tokens: id, subject, issuer, serial_number, not_before,
     * not_after, key_type, can_sign, algorithms and pem. Use it to choose a certificate ID.
     *
     * @return list<array<string, mixed>>
     */
    public function certificates(): array
    {
        $body = $this->call('GET', '/certificates');
        $list = is_array($body['certificates'] ?? null) ? array_values(array_filter($body['certificates'], 'is_array')) : [];
        if ($list === [] && is_array($body['errors'] ?? null) && $body['errors'] !== []) {
            $first = $body['errors'][0];
            throw new SigningError('Signing agent: ' . (is_array($first) && is_string($first['message'] ?? null) ? $first['message'] : 'no token is available.'));
        }

        return $list;
    }

    public function name(): string
    {
        return 'agent:' . $this->certificateId;
    }

    public function supports(SignatureFormat $format): bool
    {
        $info = $this->info();

        return ($info['can_sign'] ?? false) === true && match ($format) {
            SignatureFormat::RawRsa => ($info['key_type'] ?? null) === 'rsa',
            SignatureFormat::RawEcdsa => ($info['key_type'] ?? null) === 'ec',
            default => false,
        };
    }

    public function sign(string $data, SignatureFormat $format): string
    {
        if (! $this->supports($format)) {
            throw SigningError::unsupported($this->name(), $format);
        }
        $algorithm = $format === SignatureFormat::RawRsa ? 'rsa-sha256' : 'ecdsa-sha256';
        $body = $this->call('POST', '/sign', (string) json_encode([
            'certificate' => $this->certificateId,
            'algorithm' => $algorithm,
            'data' => base64_encode($data),
        ]));

        $signature = is_string($body['signature'] ?? null) ? base64_decode($body['signature'], true) : false;
        if ($signature === false || $signature === '') {
            throw new SigningError('Signing agent: the answer has no signature.');
        }

        return $signature;
    }

    public function certificate(): ?string
    {
        $pem = $this->info()['pem'] ?? null;

        return is_string($pem) ? $pem : null;
    }

    /** Keep the API key out of var_dump(), dd() and error trackers. */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'certificate' => $this->certificateId];
    }

    /** @return array<string, mixed> */
    private function info(): array
    {
        if ($this->info !== null) {
            return $this->info;
        }
        foreach ($this->certificates() as $certificate) {
            if (($certificate['id'] ?? null) === $this->certificateId) {
                return $this->info = $certificate;
            }
        }
        throw new SigningError("Signing agent: no certificate {$this->certificateId}. Run `signing-agent certificates` to list the IDs.");
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?string $json = null): array
    {
        $request = $this->requests->createRequest($method, $this->url . $path)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Accept', 'application/json');
        if ($json !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($json));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SigningError("Signing agent at {$this->url} is not reachable: " . $e->getMessage() . ' Is it running?', previous: $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $body = is_array($decoded) ? $decoded : [];
        if ($response->getStatusCode() >= 400) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'no message';
            $code = is_string($error['code'] ?? null) ? $error['code'] : 'error';
            throw new SigningError("Signing agent: HTTP {$response->getStatusCode()} {$code}. {$message}");
        }

        return $body;
    }

    /** Plain HTTP only to this machine: elsewhere the API key and the data would cross the network in clear. */
    private static function checkUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['path']) && $parts['path'] !== '/' || isset($parts['query'])) {
            throw new SigningError("Signing agent URL must look like http://127.0.0.1:8765, not \"{$url}\".");
        }
        $loopback = $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
        if ($scheme === 'http' && ! $loopback) {
            throw new SigningError("Signing agent at {$host}: use https for an agent on another machine.");
        }

        return rtrim($url, '/');
    }
}

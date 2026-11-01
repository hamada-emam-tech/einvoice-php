<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\KeySource\Agent\Tests\Fixtures;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Einvoice\KeySource\Agent\AgentKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers like the Signing Agent: lists one RSA certificate ("main:01") and signs with its key.
 * The key and certificate are made fresh for each run and never written to disk.
 */
final class FakeAgent implements ClientInterface
{
    public const API_KEY = 'test-api-key-0123456789-abcdefghijklmnop';

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> answers to send before the normal behaviour */
    private array $scripted = [];

    public readonly \OpenSSLAsymmetricKey $key;
    public readonly string $certificatePem;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = $key instanceof \OpenSSLAsymmetricKey ? openssl_csr_new(['commonName' => 'Test Signer', 'countryName' => 'EG'], $key, ['digest_alg' => 'sha256']) : false;
        $cert = $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']) : false;
        if (! $key instanceof \OpenSSLAsymmetricKey || $cert === false || ! openssl_x509_export($cert, $pem)) {
            throw new \RuntimeException('OpenSSL could not make the test certificate.');
        }
        $this->key = $key;
        $this->certificatePem = $pem;
    }

    public function key(string $certificate = 'main:01', string $url = 'http://127.0.0.1:8765', string $apiKey = self::API_KEY): AgentKey
    {
        $factory = new HttpFactory();

        return AgentKey::connect($url, $apiKey, $certificate, ['http_client' => $this, 'request_factory' => $factory, 'stream_factory' => $factory]);
    }

    /** @param array<string, mixed> $body */
    public function reply(int $status, array $body): self
    {
        $this->scripted[] = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->scripted !== []) {
            return array_shift($this->scripted);
        }
        if ($request->getHeaderLine('Authorization') !== 'Bearer ' . self::API_KEY) {
            return self::json(401, ['error' => ['code' => 'unauthorized', 'message' => 'Send the API key as: Authorization: Bearer <key>.']]);
        }

        return match ([$request->getMethod(), $request->getUri()->getPath()]) {
            ['GET', '/certificates'] => self::json(200, ['certificates' => [[
                'id' => 'main:01', 'token' => 'main', 'subject' => 'C=EG,CN=Test Signer', 'key_type' => 'rsa',
                'can_sign' => true, 'algorithms' => ['rsa-sha256'], 'pem' => $this->certificatePem,
            ]], 'errors' => []]),
            ['POST', '/sign'] => $this->sign($request),
            default => self::json(404, ['error' => ['code' => 'not_found', 'message' => 'No endpoint.']]),
        };
    }

    private function sign(RequestInterface $request): ResponseInterface
    {
        /** @var array{certificate: string, algorithm: string, data: string} $body */
        $body = json_decode((string) $request->getBody(), true);
        openssl_sign((string) base64_decode($body['data'], true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return self::json(200, ['signature' => base64_encode($signature), 'certificate' => $body['certificate'], 'algorithm' => $body['algorithm']]);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}

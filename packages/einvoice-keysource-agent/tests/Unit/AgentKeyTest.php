<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\KeySource\Agent\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use HamadaEmamTech\Einvoice\Exceptions\SigningError;
use HamadaEmamTech\Einvoice\KeySource\Agent\Tests\Fixtures\FakeAgent;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AgentKeyTest extends TestCase
{
    #[Test]
    public function an_rsa_certificate_supports_raw_rsa_only(): void
    {
        $key = (new FakeAgent())->key();

        self::assertTrue($key->supports(SignatureFormat::RawRsa));
        self::assertFalse($key->supports(SignatureFormat::RawEcdsa));
        self::assertFalse($key->supports(SignatureFormat::CadesBes));
        self::assertSame('agent:main:01', $key->name());
    }

    #[Test]
    public function it_sends_the_bytes_and_returns_a_signature_that_verifies(): void
    {
        $agent = new FakeAgent();
        $data = "\x31\x82signed attributes";

        $signature = $agent->key()->sign($data, SignatureFormat::RawRsa);

        self::assertSame(1, openssl_verify($data, $signature, $agent->certificatePem, OPENSSL_ALGO_SHA256));
        $sign = $agent->requests[1];
        self::assertSame('POST', $sign->getMethod());
        self::assertSame('http://127.0.0.1:8765/sign', (string) $sign->getUri());
        self::assertSame('Bearer ' . FakeAgent::API_KEY, $sign->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sign->getHeaderLine('Content-Type'));
        self::assertSame(['certificate' => 'main:01', 'algorithm' => 'rsa-sha256', 'data' => base64_encode($data)], json_decode((string) $sign->getBody(), true));
    }

    #[Test]
    public function the_certificate_comes_from_the_agent_and_is_read_once(): void
    {
        $agent = new FakeAgent();
        $key = $agent->key();

        self::assertSame($agent->certificatePem, $key->certificate());
        $key->supports(SignatureFormat::RawRsa);
        $key->sign('a', SignatureFormat::RawRsa);
        $key->sign('b', SignatureFormat::RawRsa);

        self::assertSame(['GET /certificates', 'POST /sign', 'POST /sign'], array_map(fn (RequestInterface $r) => $r->getMethod() . ' ' . $r->getUri()->getPath(), $agent->requests));
    }

    #[Test]
    public function an_unknown_certificate_id_says_how_to_list_them(): void
    {
        $this->expectException(SigningError::class);
        $this->expectExceptionMessage('no certificate main:09. Run `signing-agent certificates`');

        (new FakeAgent())->key('main:09')->supports(SignatureFormat::RawRsa);
    }

    #[Test]
    public function the_agents_error_reaches_the_caller(): void
    {
        $agent = (new FakeAgent())->reply(503, ['error' => ['code' => 'pin_incorrect', 'message' => 'Token "main" refused the PIN.']]);

        try {
            $agent->key()->certificate();
            self::fail('No error.');
        } catch (SigningError $e) {
            self::assertSame('Signing agent: HTTP 503 pin_incorrect. Token "main" refused the PIN.', $e->getMessage());
        }
    }

    #[Test]
    public function a_wrong_api_key_is_reported_without_showing_it(): void
    {
        try {
            (new FakeAgent())->key(apiKey: 'wrong-secret-key')->certificate();
            self::fail('No error.');
        } catch (SigningError $e) {
            self::assertStringContainsString('HTTP 401 unauthorized', $e->getMessage());
            self::assertStringNotContainsString('wrong-secret-key', $e->getMessage());
        }
    }

    #[Test]
    public function a_token_error_with_no_certificates_is_reported(): void
    {
        $agent = (new FakeAgent())->reply(200, ['certificates' => [], 'errors' => [['token' => 'main', 'code' => 'token_not_present', 'message' => 'Token "main" is not connected.']]]);

        $this->expectExceptionMessage('Signing agent: Token "main" is not connected.');
        $agent->key()->certificate();
    }

    #[Test]
    public function an_agent_that_is_not_running_is_reported(): void
    {
        $down = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new ConnectException('Connection refused', $request);
            }
        };
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $key = \HamadaEmamTech\Einvoice\KeySource\Agent\AgentKey::connect('http://127.0.0.1:8765', FakeAgent::API_KEY, 'main:01', ['http_client' => $down, 'request_factory' => $factory, 'stream_factory' => $factory]);

        $this->expectExceptionMessage('Signing agent at http://127.0.0.1:8765 is not reachable: Connection refused Is it running?');
        $key->certificate();
    }

    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'plain http to another machine' => ['http://192.168.1.20:8765'];
        yield 'plain http to a name' => ['http://signer.office.lan:8765'];
        yield 'a path' => ['http://127.0.0.1:8765/sign'];
        yield 'no scheme' => ['127.0.0.1:8765'];
        yield 'another scheme' => ['ftp://127.0.0.1'];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function unsafe_or_wrong_urls_are_refused(string $url): void
    {
        $this->expectException(SigningError::class);
        (new FakeAgent())->key(url: $url);
    }

    #[Test]
    public function https_to_another_machine_and_loopback_names_are_accepted(): void
    {
        $agent = new FakeAgent();
        foreach (['https://signer.office.lan:8765', 'http://localhost:8765/', 'http://[::1]:8765'] as $url) {
            $agent->key(url: $url);
        }
        $this->addToAssertionCount(3);
    }

    #[Test]
    public function the_api_key_never_appears_in_a_dump(): void
    {
        self::assertStringNotContainsString(FakeAgent::API_KEY, print_r((new FakeAgent())->key(), true));
    }
}

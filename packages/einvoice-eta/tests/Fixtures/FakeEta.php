<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Tests\Fixtures;

use GuzzleHttp\Psr7\HttpFactory;
use HamadaEmamTech\Einvoice\Eta\EtaAuthority;
use HamadaEmamTech\Einvoice\Eta\EtaFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Einvoice\Model\Address;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Money;
use HamadaEmamTech\Einvoice\Model\Party;
use HamadaEmamTech\Einvoice\Model\Tax;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A scripted ETA API plus the invoices and keys the tests share. Keys and certificates are
 * made fresh for each test run and never written to the repository.
 */
final class FakeEta implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @var array<string, SoftwareKey> */
    private static array $keys = [];

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    public function reply(int $status, array|string $body = [], array $headers = []): self
    {
        $content = is_string($body) ? $body : ($body === [] ? '' : (string) json_encode($body));
        $this->responses[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $content);

        return $this;
    }

    /** The identity service's answer to a client-credentials login. */
    public function token(string $token = 'tok-1', int $expiresIn = 3600): self
    {
        return $this->reply(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn, 'scope' => 'InvoicingAPI']);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new \LogicException('No scripted response left for ' . $request->getUri());
    }

    public function body(int $index): string
    {
        return (string) ($this->requests[$index] ?? throw new \LogicException("No request {$index}."))->getBody();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed> config for EtaFactory
     */
    public function config(array $extra = []): array
    {
        $factory = new HttpFactory();

        return $extra + [
            'environment' => 'preprod',
            'client_id' => 'client-1',
            'client_secret' => 's3cret',
            'activity_code' => '4620',
            'http_client' => $this,
            'request_factory' => $factory,
            'stream_factory' => $factory,
        ];
    }

    /** @param array<string, mixed> $extra */
    public function authority(array $extra = []): EtaAuthority
    {
        $authority = (new EtaFactory())->create($this->config($extra));
        assert($authority instanceof EtaAuthority);

        return $authority;
    }

    /** A throwaway key with a self-signed certificate, like the one on an ETA USB token. */
    public static function key(string $type = 'rsa'): SoftwareKey
    {
        if (! isset(self::$keys[$type])) {
            $options = $type === 'rsa'
                ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
                : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
            $private = openssl_pkey_new($options) ?: throw new \RuntimeException('openssl_pkey_new failed');
            $csr = openssl_csr_new(['commonName' => 'Example Trading Co', 'countryName' => 'EG'], $private, ['digest_alg' => 'sha256']);
            if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
                throw new \RuntimeException('openssl_csr_new failed');
            }
            $certificate = openssl_csr_sign($csr, null, $private, 30, ['digest_alg' => 'sha256'], 4242);
            openssl_x509_export($certificate ?: throw new \RuntimeException('openssl_csr_sign failed'), $pem);
            openssl_pkey_export($private, $keyPem);
            self::$keys[$type] = SoftwareKey::fromPem($keyPem)->withCertificate($pem);
        }

        return self::$keys[$type];
    }

    public static function seller(): Party
    {
        return new Party('Example Trading Co', '100000001', new Address('EG', 'Nasr City', '12 Abbas El Akkad St', '12', region: 'Cairo'), ['branch_id' => '0']);
    }

    public static function buyer(): Party
    {
        return new Party('Example Buyer Co', '200000002', new Address('EG', 'Dokki', '5 Tahrir St', '5', region: 'Giza'));
    }

    /** @param array<string, mixed> $extra */
    public static function invoice(string $number = 'INV-1', ?Party $buyer = null, InvoiceType $type = InvoiceType::Invoice, array $extra = []): Invoice
    {
        $laptop = Money::of('1000.00', 'EGP');
        $cable = Money::of('10.50', 'EGP');

        return new Invoice(
            number: $number,
            issuedAt: new \DateTimeImmutable('2026-11-01 12:30:00', new \DateTimeZone('Africa/Cairo')),
            seller: self::seller(),
            buyer: $buyer ?? self::buyer(),
            lines: [
                new Line('Laptop', '2', $laptop, [Tax::percent('T1', '14', $laptop->times('2')->minus(Money::of('100.00', 'EGP')), exemptionCode: 'V009')], Money::of('100.00', 'EGP'), 'EG-100000001-1001'),
                new Line('Cable', '0.333', $cable, [Tax::percent('T1', '14', $cable->times('0.333'), exemptionCode: 'V009')], null, 'EG-100000001-1002', ['unit_type' => 'KGM']),
            ],
            currency: 'EGP',
            type: $type,
            referencedInvoice: $type === InvoiceType::Invoice ? null : 'ORIGINALUUID0000000000000A',
            extra: $extra,
        );
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Tests\Fixtures;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use HamadaEmamTech\Einvoice\Model\Address;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Money;
use HamadaEmamTech\Einvoice\Model\Party;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A scripted Fatoora API plus the invoices and the key the tests share.
 * test-egs.key and test-egs.crt are a throwaway self-signed pair made for these tests only.
 */
final class FakeZatca implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @param array<string, mixed> $body */
    public function reply(int $status, array $body = []): self
    {
        $this->responses[] = new Response($status, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new \LogicException('No scripted response left for ' . $request->getUri());
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        $request = end($this->requests) ?: throw new \LogicException('No request was sent.');

        return (array) json_decode((string) $request->getBody(), true);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed> config for ZatcaFactory and Onboarding
     */
    public function config(array $extra = []): array
    {
        $factory = new HttpFactory();

        return $extra + ['environment' => 'sandbox', 'http_client' => $this, 'request_factory' => $factory, 'stream_factory' => $factory];
    }

    public static function key(): SoftwareKey
    {
        return SoftwareKey::fromPem((string) file_get_contents(__DIR__ . '/test-egs.key'))->withCertificate(self::certificate());
    }

    public static function certificate(): string
    {
        return (string) file_get_contents(__DIR__ . '/test-egs.crt');
    }

    public static function seller(): Party
    {
        return new Party('Example Trading Co', '399999999900003', new Address('SA', 'Riyadh', 'King Fahd Road', '1234', 'Al Olaya', '12345', null, '5678'), ['CRN' => '1010010000']);
    }

    public static function buyer(): Party
    {
        return new Party('Buyer Co', '399999999800003', new Address('SA', 'Riyadh', 'Olaya Street', '2222', 'Al Olaya', '12222', null, '1111'));
    }

    /** @param array<string, mixed> $zatca */
    public static function invoice(string $number = 'INV-1', ?Party $buyer = null, InvoiceType $type = InvoiceType::Invoice, array $zatca = ['icv' => 1]): Invoice
    {
        return new Invoice(
            $number,
            new \DateTimeImmutable('2026-10-25 10:30:00', new \DateTimeZone('Asia/Riyadh')),
            self::seller(),
            $buyer,
            [
                Line::taxed('Item A', '2', Money::of('50.00', 'SAR'), 'S', '15'),
                Line::taxed('Item B', '1', Money::of('20.00', 'SAR'), 'S', '15', Money::of('5.00', 'SAR')),
            ],
            'SAR',
            $type,
            referencedInvoice: $type === InvoiceType::Invoice ? null : 'INV-1',
            extra: ['zatca' => $zatca],
        );
    }
}

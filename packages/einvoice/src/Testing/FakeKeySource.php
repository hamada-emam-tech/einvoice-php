<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Testing;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use PHPUnit\Framework\Assert;

/**
 * A key source for tests: supports every format, returns a deterministic fake signature,
 * and remembers what it signed.
 */
final class FakeKeySource implements KeySource
{
    /** @var list<array{data: string, format: SignatureFormat}> */
    private array $signed = [];

    public function __construct(private readonly ?string $certificatePem = null)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function supports(SignatureFormat $format): bool
    {
        return true;
    }

    public function sign(string $data, SignatureFormat $format): string
    {
        $this->signed[] = ['data' => $data, 'format' => $format];

        return hash_hmac('sha256', $format->value . "\n" . $data, 'fake-key', true);
    }

    public function certificate(): ?string
    {
        return $this->certificatePem;
    }

    public function assertSigned(int $times = 1): void
    {
        Assert::assertCount($times, $this->signed, 'The fake key source signed a different number of times.');
    }

    public function assertNothingSigned(): void
    {
        Assert::assertSame([], $this->signed, 'The fake key source signed something.');
    }
}

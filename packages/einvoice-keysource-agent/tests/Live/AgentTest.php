<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\KeySource\Agent\Tests\Live;

use HamadaEmamTech\Einvoice\KeySource\Agent\AgentKey;
use HamadaEmamTech\Einvoice\Signing\SignatureFormat;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Signs through a running Signing Agent and checks the signature with the agent's certificate.
 *
 *     AGENT_LIVE=1 SIGNING_AGENT_URL=http://127.0.0.1:8765 SIGNING_AGENT_API_KEY=... \
 *     SIGNING_AGENT_CERTIFICATE=main:01 vendor/bin/phpunit --group live
 */
#[Group('live')]
final class AgentTest extends TestCase
{
    #[Test]
    public function sign_through_the_running_agent(): void
    {
        if (getenv('AGENT_LIVE') !== '1') {
            self::markTestSkipped('Set AGENT_LIVE=1 and the SIGNING_AGENT_* variables to call a running agent.');
        }
        $key = AgentKey::connect(self::env('SIGNING_AGENT_URL'), self::env('SIGNING_AGENT_API_KEY'), self::env('SIGNING_AGENT_CERTIFICATE'));
        $format = $key->supports(SignatureFormat::RawRsa) ? SignatureFormat::RawRsa : SignatureFormat::RawEcdsa;
        $data = random_bytes(300);

        $signature = $key->sign($data, $format);

        self::assertSame(1, openssl_verify($data, $signature, (string) $key->certificate(), OPENSSL_ALGO_SHA256));
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : self::markTestSkipped("Set {$name}.");
    }
}

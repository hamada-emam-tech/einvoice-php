<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca;

use HamadaEmamTech\Einvoice\Zatca\Signing\Certificate;

/**
 * A CSID as the API returns it: the certificate (binarySecurityToken) and its secret.
 * Store both; the secret is a credential, keep it with your other secrets.
 */
final class Csid
{
    public function __construct(
        public readonly string $binarySecurityToken,
        #[\SensitiveParameter] private readonly string $secret,
        /** Compliance CSIDs only: needed to ask for the production CSID. */
        public readonly ?string $requestId = null,
    ) {
    }

    /** @param array<string, mixed> $response the CSID API's JSON */
    public static function fromResponse(array $response): self
    {
        return new self(
            (string) ($response['binarySecurityToken'] ?? ''),
            (string) ($response['secret'] ?? ''),
            isset($response['requestID']) ? (string) $response['requestID'] : null,
        );
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /** The certificate to attach to your key: SoftwareKey::fromFile(...)->withCertificate($csid->certificatePem()). */
    public function certificatePem(): string
    {
        return Certificate::from($this->binarySecurityToken)->pem();
    }

    public function basic(): string
    {
        return 'Basic ' . base64_encode($this->binarySecurityToken . ':' . $this->secret);
    }

    /** Keep the secret out of var_dump(), dd() and error trackers. */
    public function __debugInfo(): array
    {
        return ['requestId' => $this->requestId, 'secret' => '***'];
    }
}

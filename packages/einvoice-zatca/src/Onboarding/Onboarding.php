<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Onboarding;

use HamadaEmamTech\Einvoice\Contracts\KeySource;
use HamadaEmamTech\Einvoice\Document\SignedDocument;
use HamadaEmamTech\Einvoice\Exceptions\AuthorityError;
use HamadaEmamTech\Einvoice\Result\SubmissionResult;
use HamadaEmamTech\Einvoice\Zatca\Api\ZatcaApi;
use HamadaEmamTech\Einvoice\Zatca\Csid;
use HamadaEmamTech\Einvoice\Zatca\Environment;
use HamadaEmamTech\Einvoice\Zatca\ZatcaAuthority;
use HamadaEmamTech\Einvoice\Zatca\ZatcaFactory;

/**
 * Onboards one EGS unit, once:
 *
 *     $onboarding = Onboarding::create(['environment' => 'sandbox']);
 *     $csr        = $onboarding->csr($details, $key, $key->publicKeyPem());
 *     $compliance = $onboarding->complianceCsid($csr, $otp);         // OTP from the Fatoora portal; sandbox: 123345
 *     // sign one test document of each type you declared, with the compliance certificate, and check it:
 *     $onboarding->check($compliance, ...$signed);
 *     $production = $onboarding->productionCsid($compliance);        // store binarySecurityToken + secret
 */
final class Onboarding
{
    public function __construct(
        private readonly ZatcaApi $api,
        private readonly Environment $environment,
    ) {
    }

    /** @param array<string, mixed> $config the same block as the authority: environment, base_url, http_client... */
    public static function create(array $config = []): self
    {
        return new self(ZatcaFactory::api($config), ZatcaFactory::environment($config));
    }

    /** The CSR as PEM, with this environment's certificate template. */
    public function csr(CsrDetails $details, KeySource $key, string $publicKeyPem): string
    {
        return Csr::create($details, $key, $publicKeyPem, $this->environment);
    }

    /** Step 1: the compliance CSID, a certificate valid only for the compliance checks. */
    public function complianceCsid(string $csrPem, #[\SensitiveParameter] string $otp): Csid
    {
        [$status, $body] = $this->api->post('/compliance', ['csr' => base64_encode($csrPem)], ['OTP' => $otp]);

        return self::csid($status, $body, 'compliance CSID');
    }

    /** Step 2: send your signed test documents to /compliance/invoices. */
    public function check(Csid $compliance, SignedDocument ...$documents): SubmissionResult
    {
        return (new ZatcaAuthority($this->api, $compliance, compliance: true))->submit(...$documents);
    }

    /** Step 3: the production CSID, once every declared invoice type passed its check. */
    public function productionCsid(Csid $compliance): Csid
    {
        if ($compliance->requestId === null) {
            throw new AuthorityError('zatca', 'The compliance CSID has no requestID; ask for a new compliance CSID.');
        }
        [$status, $body] = $this->api->post('/production/csids', ['compliance_request_id' => $compliance->requestId], [], $compliance);

        return self::csid($status, $body, 'production CSID');
    }

    /** @param array<string, mixed> $body */
    private static function csid(int $status, array $body, string $what): Csid
    {
        if ($status !== 200 || ! is_string($body['binarySecurityToken'] ?? null) || ! is_string($body['secret'] ?? null)) {
            $errors = is_array($body['errors'] ?? null) ? json_encode($body['errors'], JSON_UNESCAPED_SLASHES) : ($body['dispositionMessage'] ?? '');

            throw new AuthorityError('zatca', "ZATCA did not issue the {$what} (HTTP {$status}): " . (is_string($errors) ? $errors : ''), $status);
        }

        return Csid::fromResponse($body);
    }
}

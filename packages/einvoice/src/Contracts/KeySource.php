<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Contracts;

use HamadaEmamTech\Einvoice\Signing\SignatureFormat;

/**
 * Where the signing key lives: a key file (SoftwareKey), a USB token or HSM behind the
 * Signing Agent, or a cloud signing service. The authority package never knows which.
 *
 * Canonicalization is the authority's job. A key source only signs the bytes it is given.
 */
interface KeySource
{
    /** A short label for logs and errors, e.g. "software", "agent:token-1". Never key material. */
    public function name(): string;

    public function supports(SignatureFormat $format): bool;

    /**
     * @return string the signature bytes (DER for ECDSA and CMS)
     *
     * @throws \HamadaEmamTech\Einvoice\Exceptions\SigningError
     */
    public function sign(string $data, SignatureFormat $format): string;

    /** The signing certificate as PEM, or null when there is none yet (e.g. a key waiting for its ZATCA CSID). */
    public function certificate(): ?string;
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Signing;

/** What a KeySource is asked to produce. The authority package decides which one it needs. */
enum SignatureFormat: string
{
    /** ECDSA over SHA-256 of the data, DER-encoded. ZATCA. */
    case RawEcdsa = 'raw-ecdsa';

    /** RSA PKCS#1 v1.5 over SHA-256 of the data. */
    case RawRsa = 'raw-rsa';

    /** CMS / CAdES-BES detached signature, DER-encoded, with the signing certificate. Egypt ETA. */
    case CadesBes = 'cades-bes';

    /** XML signature (XAdES). */
    case Xades = 'xades';

    /** Signed PDF (PAdES). */
    case Pades = 'pades';
}

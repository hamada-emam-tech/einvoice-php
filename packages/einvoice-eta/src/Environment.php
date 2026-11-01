<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta;

/**
 * ETA's two environments. Each has an identity service (login) and an API base URL,
 * as listed in the SDK (sdk.invoicing.eta.gov.eg).
 */
enum Environment: string
{
    /** Pre-production: test with the credentials of a preprod ERP system. */
    case Preprod = 'preprod';
    case Production = 'production';

    public function idUrl(): string
    {
        return match ($this) {
            self::Preprod => 'https://id.preprod.eta.gov.eg',
            self::Production => 'https://id.eta.gov.eg',
        };
    }

    public function apiUrl(): string
    {
        return match ($this) {
            self::Preprod => 'https://api.preprod.invoicing.eta.gov.eg',
            self::Production => 'https://api.invoicing.eta.gov.eg',
        };
    }
}

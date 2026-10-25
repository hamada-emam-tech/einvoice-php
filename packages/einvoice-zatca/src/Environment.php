<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca;

/**
 * ZATCA's three Fatoora environments. Each has its own base URL and its own certificate
 * template name in the CSR.
 */
enum Environment: string
{
    /** Developer portal: public, OTP 123345, no Fatoora account needed. */
    case Sandbox = 'sandbox';
    /** Simulation: a real taxpayer's onboarding rehearsal, OTP from the Fatoora simulation portal. */
    case Simulation = 'simulation';
    case Production = 'production';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            self::Simulation => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            self::Production => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
        };
    }

    /** The value of the certificate-template extension (1.3.6.1.4.1.311.20.2) in the CSR. */
    public function certificateTemplate(): string
    {
        return match ($this) {
            self::Sandbox => 'TSTZATCA-Code-Signing',
            self::Simulation => 'PREZATCA-Code-Signing',
            self::Production => 'ZATCA-Code-Signing',
        };
    }
}

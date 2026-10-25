<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Signing;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;

/**
 * The QR payload: TLV (tag, one length byte, value), base64-encoded.
 *
 * 1 seller name · 2 VAT number · 3 timestamp · 4 total with VAT · 5 VAT total ·
 * 6 invoice hash · 7 signature · 8 public key · 9 the CA's signature on the certificate (simplified only)
 */
final class Qr
{
    /** @param array<int, string> $tags tag number => value bytes */
    public static function encode(array $tags): string
    {
        ksort($tags);
        $tlv = '';
        foreach ($tags as $tag => $value) {
            if (strlen($value) > 255) {
                throw new InvalidInvoice("QR tag {$tag} is longer than 255 bytes.");
            }
            $tlv .= chr($tag) . chr(strlen($value)) . $value;
        }

        return base64_encode($tlv);
    }

    /** @return array<int, string> */
    public static function decode(string $base64): array
    {
        $tlv = (string) base64_decode($base64, true);
        $tags = [];
        for ($i = 0; $i + 1 < strlen($tlv);) {
            $length = ord($tlv[$i + 1]);
            $tags[ord($tlv[$i])] = substr($tlv, $i + 2, $length);
            $i += 2 + $length;
        }

        return $tags;
    }
}

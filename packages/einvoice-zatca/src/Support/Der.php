<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Zatca\Support;

use HamadaEmamTech\Einvoice\Exceptions\EinvoiceException;

/**
 * The few DER (ASN.1) pieces ZATCA needs: building a CSR and reading a certificate's
 * signature for the QR code. Not a general ASN.1 library.
 *
 * @internal
 */
final class Der
{
    public static function sequence(string ...$items): string
    {
        return self::tlv(0x30, implode('', $items));
    }

    public static function set(string ...$items): string
    {
        return self::tlv(0x31, implode('', $items));
    }

    public static function integer(int $value): string
    {
        $bytes = ltrim(pack('J', $value), "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\0" . $bytes;
        }

        return self::tlv(0x02, $bytes);
    }

    public static function oid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        $bytes = chr(40 * $parts[0] + $parts[1]);
        foreach (array_slice($parts, 2) as $part) {
            $chunk = chr($part & 0x7F);
            while (($part >>= 7) > 0) {
                $chunk = chr(0x80 | ($part & 0x7F)) . $chunk;
            }
            $bytes .= $chunk;
        }

        return self::tlv(0x06, $bytes);
    }

    public static function utf8(string $value): string
    {
        return self::tlv(0x0C, $value);
    }

    public static function printable(string $value): string
    {
        return self::tlv(0x13, $value);
    }

    public static function octets(string $value): string
    {
        return self::tlv(0x04, $value);
    }

    public static function bits(string $value): string
    {
        return self::tlv(0x03, "\0" . $value);
    }

    /** A context-specific constructed tag: [n] EXPLICIT or IMPLICIT SET/SEQUENCE. */
    public static function context(int $number, string $content): string
    {
        return self::tlv(0xA0 | $number, $content);
    }

    public static function tlv(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }

    /**
     * The children of a constructed element, as [tag, full TLV bytes, content] triples.
     *
     * @return list<array{int, string, string}>
     */
    public static function children(string $der): array
    {
        [, , $content] = self::read($der, 0);
        $children = [];
        $offset = 0;
        while ($offset < strlen($content)) {
            [$tag, $full, $inner] = self::read($content, $offset);
            $children[] = [$tag, $full, $inner];
            $offset += strlen($full);
        }

        return $children;
    }

    /** @return array{int, string, string} tag, the whole TLV, the content */
    private static function read(string $der, int $offset): array
    {
        if (strlen($der) < $offset + 2) {
            throw new EinvoiceException('Malformed DER: truncated.');
        }
        $tag = ord($der[$offset]);
        $length = ord($der[$offset + 1]);
        $header = 2;
        if ($length & 0x80) {
            $count = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$offset + 2 + $i] ?? "\0");
            }
            $header += $count;
        }
        if (strlen($der) < $offset + $header + $length) {
            throw new EinvoiceException('Malformed DER: length runs past the end.');
        }

        return [$tag, substr($der, $offset, $header + $length), substr($der, $offset + $header, $length)];
    }
}

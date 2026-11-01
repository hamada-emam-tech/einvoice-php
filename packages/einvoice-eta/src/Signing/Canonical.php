<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Signing;

use HamadaEmamTech\Einvoice\Eta\Json\Json;
use HamadaEmamTech\Einvoice\Eta\Json\Number;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use stdClass;

/**
 * ETA's document serialization, the text that is hashed and signed
 * (sdk.invoicing.eta.gov.eg/document-serialization-approach):
 *
 * - every property name is upper-cased and quoted: "ISSUER"
 * - every simple value is quoted exactly as written in the document: "14.00", "Cairo"
 * - an object is its properties, one after another, with no braces
 * - an array writes its name once, then its name again before each item:
 *   "TAXABLEITEMS""TAXABLEITEMS"<item 1>"TAXABLEITEMS"<item 2>
 *
 * The document's own "signatures" are left out, because they sign this text.
 */
final class Canonical
{
    /** @param array<string, mixed> $document as Json::decode() returns it, numbers as Number */
    public static function serialize(array $document): string
    {
        unset($document['signatures']);

        return self::object($document);
    }

    /** @param array<array-key, mixed> $object */
    private static function object(array $object): string
    {
        $out = '';
        foreach ($object as $name => $value) {
            $name = '"' . strtoupper((string) $name) . '"';
            $out .= $name;
            if (is_array($value) && array_is_list($value) && $value !== []) {
                foreach ($value as $item) {
                    $out .= $name . self::value($item);
                }
                continue;
            }
            $out .= self::value($value);
        }

        return $out;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            is_array($value) => self::object($value),
            $value instanceof stdClass, $value === null => '',
            is_string($value) => Json::string($value),
            $value instanceof Number => '"' . $value->text . '"',
            is_int($value) => '"' . $value . '"',
            default => throw new InvalidInvoice(sprintf(
                'ETA cannot hash a %s exactly. Write amounts as Number::of("1.50"), not floats; ETA documents have no true/false fields.',
                get_debug_type($value),
            )),
        };
    }
}

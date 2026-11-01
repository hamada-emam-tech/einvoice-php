<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Json;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;

/**
 * A JSON number kept as its exact text. ETA hashes numbers as written ("14.00" stays "14.00"),
 * so a float, which would print 14, can never be used.
 */
final class Number
{
    private function __construct(public readonly string $text)
    {
    }

    public static function of(string|int $value): self
    {
        $text = (string) $value;
        if (preg_match('/^-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?$/', $text) !== 1) {
            throw new InvalidInvoice(sprintf('"%s" is not a JSON number.', $text));
        }

        return new self($text);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}

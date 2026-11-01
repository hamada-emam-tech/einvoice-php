<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Json;

use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use stdClass;

/**
 * Reads and writes JSON without touching number text. json_decode() turns 14.00 into 14 and
 * json_encode() can print 0.1 + 0.2 as 0.30000000000000004; either one changes ETA's hash.
 *
 * Objects become associative arrays (an empty object becomes stdClass), lists stay lists and
 * numbers become Number.
 */
final class Json
{
    private const STRING_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    private int $at = 0;

    private function __construct(private readonly string $text)
    {
    }

    public static function decode(string $json): mixed
    {
        $json = str_starts_with($json, "\xEF\xBB\xBF") ? substr($json, 3) : $json;
        $reader = new self($json);
        $value = $reader->value();
        $reader->skipSpace();
        if ($reader->at !== strlen($json)) {
            $reader->fail('unexpected text after the end');
        }

        return $value;
    }

    public static function encode(mixed $value): string
    {
        return match (true) {
            $value instanceof Number => $value->text,
            $value instanceof stdClass => self::encodeObject((array) $value),
            is_array($value) => array_is_list($value) && $value !== []
                ? '[' . implode(',', array_map(self::encode(...), $value)) . ']'
                : ($value === [] ? '[]' : self::encodeObject($value)),
            is_string($value) => self::string($value),
            is_int($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => throw new InvalidInvoice(sprintf('Cannot write a %s to an ETA document. Use Number::of("1.50") for amounts.', get_debug_type($value))),
        };
    }

    /** A string as JSON writes it: quoted, with only quotes, backslashes and control characters escaped. */
    public static function string(string $value): string
    {
        return json_encode($value, self::STRING_FLAGS);
    }

    /** @param array<array-key, mixed> $object */
    private static function encodeObject(array $object): string
    {
        $pairs = [];
        foreach ($object as $key => $item) {
            $pairs[] = self::string((string) $key) . ':' . self::encode($item);
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function value(): mixed
    {
        $this->skipSpace();

        return match ($this->text[$this->at] ?? '') {
            '{' => $this->object(),
            '[' => $this->list(),
            '"' => $this->stringToken(),
            't' => $this->literal('true', true),
            'f' => $this->literal('false', false),
            'n' => $this->literal('null', null),
            default => $this->number(),
        };
    }

    /** @return array<string, mixed>|stdClass */
    private function object(): array|stdClass
    {
        $this->at++;
        $object = [];
        $this->skipSpace();
        if ($this->peek() === '}') {
            $this->at++;

            return new stdClass();
        }
        while (true) {
            $this->skipSpace();
            if ($this->peek() !== '"') {
                $this->fail('expected a property name');
            }
            $key = $this->stringToken();
            $this->skipSpace();
            $this->expect(':');
            $object[$key] = $this->value();
            $this->skipSpace();
            if ($this->peek() === ',') {
                $this->at++;
                continue;
            }
            $this->expect('}');

            return $object;
        }
    }

    /** @return list<mixed> */
    private function list(): array
    {
        $this->at++;
        $list = [];
        $this->skipSpace();
        if ($this->peek() === ']') {
            $this->at++;

            return $list;
        }
        while (true) {
            $list[] = $this->value();
            $this->skipSpace();
            if ($this->peek() === ',') {
                $this->at++;
                continue;
            }
            $this->expect(']');

            return $list;
        }
    }

    private function stringToken(): string
    {
        if (preg_match('/"(?:[^"\\\\\x00-\x1F]|\\\\["\\\\\/bfnrt]|\\\\u[0-9a-fA-F]{4})*"/A', $this->text, $m, 0, $this->at) !== 1) {
            $this->fail('malformed string');
        }
        $this->at += strlen($m[0]);

        return json_decode($m[0], false, 1, JSON_THROW_ON_ERROR);
    }

    private function number(): Number
    {
        if (preg_match('/-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $this->text, $m, 0, $this->at) !== 1) {
            $this->fail('unexpected character');
        }
        $this->at += strlen($m[0]);

        return Number::of($m[0]);
    }

    private function literal(string $word, ?bool $value): ?bool
    {
        if (substr($this->text, $this->at, strlen($word)) !== $word) {
            $this->fail('unexpected character');
        }
        $this->at += strlen($word);

        return $value;
    }

    private function skipSpace(): void
    {
        $this->at += strspn($this->text, " \t\r\n", $this->at);
    }

    private function peek(): string
    {
        return $this->text[$this->at] ?? '';
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            $this->fail("expected \"{$char}\"");
        }
        $this->at++;
    }

    private function fail(string $problem): never
    {
        throw new InvalidInvoice(sprintf('Invalid JSON at byte %d: %s.', $this->at, $problem));
    }
}

<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Tests\Unit;

use HamadaEmamTech\Einvoice\Eta\Json\Json;
use HamadaEmamTech\Einvoice\Eta\Json\Number;
use HamadaEmamTech\Einvoice\Eta\Signing\Canonical;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanonicalTest extends TestCase
{
    /** The sample document and its serialized text, both published in ETA's SDK. */
    #[Test]
    public function the_eta_sdk_sample_serializes_byte_for_byte(): void
    {
        $document = Json::decode((string) file_get_contents(__DIR__ . '/../Fixtures/eta-sdk-one-doc.json'));
        self::assertIsArray($document);

        self::assertSame(
            (string) file_get_contents(__DIR__ . '/../Fixtures/eta-sdk-one-doc-serialized.txt'),
            Canonical::serialize($document),
        );
    }

    #[Test]
    public function number_text_survives_a_read_and_a_write(): void
    {
        $json = '{"a":14.00,"b":0.0,"c":5,"d":[1.10,"x"],"e":{},"f":-0.50}';

        $document = Json::decode($json);

        self::assertSame($json, Json::encode($document));
        self::assertSame('"A""14.00""B""0.0""C""5""D""D""1.10""D""x""E""F""-0.50"', Canonical::serialize((array) $document));
    }

    #[Test]
    public function arrays_of_strings_repeat_the_name_and_signatures_are_left_out(): void
    {
        $document = ['references' => ['UUID1', 'UUID2'], 'internalID' => 'A"1', 'signatures' => [['signatureType' => 'I', 'value' => 'x']]];

        self::assertSame('"REFERENCES""REFERENCES""UUID1""REFERENCES""UUID2""INTERNALID""A\"1"', Canonical::serialize($document));
    }

    #[Test]
    public function arabic_text_is_hashed_as_written(): void
    {
        self::assertSame('"NAME""شركة"', Canonical::serialize(['name' => 'شركة']));
    }

    #[Test]
    public function floats_are_refused(): void
    {
        $this->expectException(InvalidInvoice::class);
        $this->expectExceptionMessage('Number::of');

        Canonical::serialize(['amount' => 14.0]);
    }

    #[Test]
    public function invalid_json_says_where(): void
    {
        $this->expectException(InvalidInvoice::class);
        $this->expectExceptionMessage('byte 7: expected a property name');

        Json::decode('{"a":1,}');
    }

    #[Test]
    public function a_number_must_look_like_one(): void
    {
        $this->expectException(InvalidInvoice::class);

        Number::of('1,5');
    }
}

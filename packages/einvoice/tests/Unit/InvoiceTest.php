<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Tests\Unit;

use DateTimeImmutable;
use HamadaEmamTech\Einvoice\Exceptions\InvalidInvoice;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\InvoiceType;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Money;
use HamadaEmamTech\Einvoice\Model\Party;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public static function invoice(string $number = 'INV-1'): Invoice
    {
        return new Invoice(
            $number,
            new DateTimeImmutable('2026-10-14T10:00:00+03:00'),
            new Party('Seller Co', '300000000000003', identifiers: ['CRN' => '1010010000']),
            new Party('Buyer Co', '311111111111113'),
            [
                Line::taxed('Widget', '3', Money::of('10.00', 'SAR'), 'S', '15'),
                Line::taxed('Service', '1.5', Money::of('100.00', 'SAR'), 'S', '15', discount: Money::of('10.00', 'SAR')),
            ],
            'SAR',
        );
    }

    #[Test]
    public function totals_add_up_from_the_lines(): void
    {
        $invoice = self::invoice();

        self::assertSame('30.00', $invoice->lines[0]->net->toDecimal());
        self::assertSame('140.00', $invoice->lines[1]->net->toDecimal());
        self::assertSame('21.00', $invoice->lines[1]->taxTotal()->toDecimal());
        self::assertSame('170.00', $invoice->netTotal()->toDecimal());
        self::assertSame('25.50', $invoice->taxTotal()->toDecimal());
        self::assertSame('195.50', $invoice->grossTotal()->toDecimal());
    }

    #[Test]
    public function a_line_in_another_currency_is_refused(): void
    {
        $this->expectException(InvalidInvoice::class);
        new Invoice('INV-1', new DateTimeImmutable(), new Party('S'), null, [Line::taxed('x', '1', Money::of('1', 'EGP'), 'S', '15')], 'SAR');
    }

    #[Test]
    public function a_credit_note_must_reference_the_original(): void
    {
        $this->expectException(InvalidInvoice::class);
        new Invoice('CN-1', new DateTimeImmutable(), new Party('S'), null, [Line::taxed('x', '1', Money::of('1', 'SAR'), 'S', '15')], 'SAR', InvoiceType::CreditNote);
    }
}

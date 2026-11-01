<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Tests\Live;

use HamadaEmamTech\Einvoice\Eta\EtaAuthority;
use HamadaEmamTech\Einvoice\Eta\EtaFactory;
use HamadaEmamTech\Einvoice\Eta\Tests\Fixtures\FakeEta;
use HamadaEmamTech\Einvoice\Model\Address;
use HamadaEmamTech\Einvoice\Model\Invoice;
use HamadaEmamTech\Einvoice\Model\Line;
use HamadaEmamTech\Einvoice\Model\Party;
use HamadaEmamTech\Einvoice\Result\DocumentStatus;
use HamadaEmamTech\Einvoice\Signing\SoftwareKey;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Submits one invoice to ETA pre-production and waits for its final status. ETA has no public
 * sandbox: this needs a preprod ERP system registered to a taxpayer, and that taxpayer's
 * signing key and certificate exported to PEM files. Nothing here is stored in the repository.
 *
 *     ETA_LIVE=1 ETA_CLIENT_ID=... ETA_CLIENT_SECRET=... ETA_TAXPAYER_ID=... ETA_ACTIVITY_CODE=... \
 *     ETA_KEY=/path/key.pem ETA_CERT=/path/cert.pem ETA_ITEM_CODE=EG-...-... \
 *     vendor/bin/phpunit --group live
 */
#[Group('live')]
final class PreprodTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('ETA_LIVE') !== '1') {
            self::markTestSkipped('Set ETA_LIVE=1 and the ETA_* variables to call ETA pre-production.');
        }
    }

    #[Test]
    public function submit_one_invoice_and_read_its_final_status(): void
    {
        $authority = (new EtaFactory())->create([
            'environment' => 'preprod',
            'client_id' => self::env('ETA_CLIENT_ID'),
            'client_secret' => self::env('ETA_CLIENT_SECRET'),
            'activity_code' => self::env('ETA_ACTIVITY_CODE'),
        ]);
        self::assertInstanceOf(EtaAuthority::class, $authority);
        $key = SoftwareKey::fromFile(self::env('ETA_KEY'), getenv('ETA_KEY_PASSPHRASE') ?: null, self::env('ETA_CERT'));

        $base = FakeEta::invoice('LIVE-' . date('YmdHis'));
        $seller = new Party($base->seller->name, self::env('ETA_TAXPAYER_ID'), $base->seller->address, $base->seller->identifiers);
        $lines = array_map(fn (Line $line) => new Line($line->description, $line->quantity, $line->unitPrice, $line->taxes, $line->discount, self::env('ETA_ITEM_CODE'), $line->extra), $base->lines);
        $invoice = new Invoice($base->number, new \DateTimeImmutable('-5 minutes'), $seller, new Party('Walk-in customer', null, new Address('EG', 'Dokki', '5 Tahrir St', '5', region: 'Giza')), $lines, 'EGP');

        $result = $authority->submit($authority->sign($authority->prepare($invoice), $key));

        self::assertSame([], $result->rejected, json_encode($result->rejected, JSON_PRETTY_PRINT) ?: '');
        $uuid = (string) $result->accepted($invoice->number)?->authorityId;
        for ($i = 0, $status = DocumentStatus::Pending; $i < 10 && ! $status->isFinal(); $i++) {
            sleep(3);
            $status = $authority->status($uuid);
        }
        self::assertSame(DocumentStatus::Accepted, $status, json_encode($authority->details($uuid)['validationResults'] ?? [], JSON_PRETTY_PRINT) ?: '');
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            self::markTestSkipped("Set {$name}.");
        }

        return $value;
    }
}

<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoWayBillPayloadAssembler;
use App\Tests\Unit\UnitTestCase;

final class BobGoWayBillPayloadAssemblerTest extends UnitTestCase
{
    public function testInvokeSuccess(): void
    {
        $reference = 'ABC123';
        $payload = (new BobGoWayBillPayloadAssembler())
            ->__invoke($reference);

        $this->assertSame([
            'tracking_references' => $reference,
            'paper_size' => 'A4',
            'waybill_size' => 'A5',
            'waybills_per_shipment' => '1',
            'show_order_items' => 'false',
            'show_email_address' => 'false',
        ], $payload);
    }
}

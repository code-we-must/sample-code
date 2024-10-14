<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoApiEndpointGenerator;
use App\Tests\Unit\UnitTestCase;

final class BobGoApiEndpointGeneratorTest extends UnitTestCase
{
    public function testSandboxUrls(): void
    {
        $endpointGenerator = new BobGoApiEndpointGenerator();

        $this->assertSame('https://api.sandbox.bobgo.co.za/v2/rates', $endpointGenerator->getRatesUrl(false));
        $this->assertSame('https://api.sandbox.bobgo.co.za/v2/shipments', $endpointGenerator->getShipmentsUrl(false));
        $this->assertSame('https://api.sandbox.bobgo.co.za/v2/shipments/waybill', $endpointGenerator->getWaybillUrl(false));
        $this->assertSame('https://api.sandbox.bobgo.co.za/v2/webhooks', $endpointGenerator->getWebhooksUrl(false));
        $this->assertSame('https://api.sandbox.bobgo.co.za/v2/tracking', $endpointGenerator->getTrackingUrl(false));
    }

    public function testProductionUrls(): void
    {
        $endpointGenerator = new BobGoApiEndpointGenerator();

        $this->assertSame('https://api.bobgo.co.za/v2/rates', $endpointGenerator->getRatesUrl(true));
        $this->assertSame('https://api.bobgo.co.za/v2/shipments', $endpointGenerator->getShipmentsUrl(true));
        $this->assertSame('https://api.bobgo.co.za/v2/shipments/waybill', $endpointGenerator->getWaybillUrl(true));
        $this->assertSame('https://api.bobgo.co.za/v2/webhooks', $endpointGenerator->getWebhooksUrl(true));
        $this->assertSame('https://api.bobgo.co.za/v2/tracking', $endpointGenerator->getTrackingUrl(true));
    }
}

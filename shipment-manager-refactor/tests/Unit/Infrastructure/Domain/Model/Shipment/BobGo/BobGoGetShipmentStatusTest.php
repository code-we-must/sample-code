<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Domain\Model\ShipmentStatus\ShipmentStatus;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoApiEndpointGenerator;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoGetShipmentStatus;
use App\Infrastructure\Domain\Model\Shipping\ShippingGatewayProxy;
use App\Tests\Unit\UnitTestCase;

final class BobGoGetShipmentStatusTest extends UnitTestCase
{
    public function testInvokeSuccessDeliveredAndPaid(): void
    {
        [$gatewayProxyMock] = $this->createMockObjects();

        $trackingResponse = [[
            'shipment_movement_events' => [
                'delivered_time' => '2024-09-12 06:35:04+00:00',
            ],
        ]];

        $gatewayProxyMock->expects($this->once())->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())->method('request')
            ->with('GET', 'https://api.sandbox.bobgo.co.za/v2/tracking?tracking_reference=UASSPW7D')
            ->willReturn($trackingResponse);
        $getShipmentStatus = new BobGoGetShipmentStatus(
            new BobGoApiEndpointGenerator(),
        );
        $getShipmentStatus->setGatewayProxy($gatewayProxyMock);

        $statusResponseData = $getShipmentStatus(new ShipmentStatusRequestData(['UASSPW7D'], []));

        $this->assertEquals(
            [
                new ShipmentStatus(
                    'UASSPW7D',
                    true,
                    'delivered',
                ),
            ],
            $statusResponseData->getData(),
        );
        $this->assertInstanceOf(\DateTime::class, $statusResponseData->getDateTime());
    }

    public function testInvokeSuccessShipped(): void
    {
        [$gatewayProxyMock] = $this->createMockObjects();

        $trackingResponse = [[
            'shipment_movement_events' => [
                'delivered_time' => '',
                'collected_time' => '2024-09-12 06:35:04+00:00',
            ],
        ]];

        $gatewayProxyMock->expects($this->once())->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())->method('request')
            ->with('GET', 'https://api.sandbox.bobgo.co.za/v2/tracking?tracking_reference=UASSPW7D')
            ->willReturn($trackingResponse);
        $getShipmentStatus = new BobGoGetShipmentStatus(
            new BobGoApiEndpointGenerator(),
        );
        $getShipmentStatus->setGatewayProxy($gatewayProxyMock);

        $statusResponseData = $getShipmentStatus(new ShipmentStatusRequestData(['UASSPW7D'], []));

        $this->assertEquals(
            [
                new ShipmentStatus(
                    'UASSPW7D',
                    false,
                    'shipped',
                ),
            ],
            $statusResponseData->getData(),
        );
        $this->assertInstanceOf(\DateTime::class, $statusResponseData->getDateTime());
    }

    public function testInvokeSuccessCanceled(): void
    {
        [$gatewayProxyMock] = $this->createMockObjects();

        $trackingResponse = [[
            'status' => 'cancelled',
            'shipment_movement_events' => [
                'delivered_time' => '',
                'collected_time' => '',
            ],
        ]];

        $gatewayProxyMock->expects($this->once())->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())->method('request')
            ->with('GET', 'https://api.sandbox.bobgo.co.za/v2/tracking?tracking_reference=UASSPW7D')
            ->willReturn($trackingResponse);
        $getShipmentStatus = new BobGoGetShipmentStatus(
            new BobGoApiEndpointGenerator(),
        );
        $getShipmentStatus->setGatewayProxy($gatewayProxyMock);

        $statusResponseData = $getShipmentStatus(new ShipmentStatusRequestData(['UASSPW7D'], []));

        $this->assertEquals(
            [
                new ShipmentStatus(
                    'UASSPW7D',
                    false,
                    'canceled',
                ),
            ],
            $statusResponseData->getData(),
        );
        $this->assertInstanceOf(\DateTime::class, $statusResponseData->getDateTime());
    }

    public function testInvokeSuccessPending(): void
    {
        [$gatewayProxyMock] = $this->createMockObjects();

        $trackingResponse = [[
            'status' => 'pending-collection',
            'shipment_movement_events' => [
                'delivered_time' => '',
                'collected_time' => '',
            ],
        ]];

        $gatewayProxyMock->expects($this->once())->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())->method('request')
            ->with('GET', 'https://api.sandbox.bobgo.co.za/v2/tracking?tracking_reference=UASSPW7D')
            ->willReturn($trackingResponse);
        $getShipmentStatus = new BobGoGetShipmentStatus(
            new BobGoApiEndpointGenerator(),
        );
        $getShipmentStatus->setGatewayProxy($gatewayProxyMock);

        $statusResponseData = $getShipmentStatus(new ShipmentStatusRequestData(['UASSPW7D'], []));

        $this->assertEquals(
            [
                new ShipmentStatus(
                    'UASSPW7D',
                    false,
                    'pending',
                ),
            ],
            $statusResponseData->getData(),
        );
        $this->assertInstanceOf(\DateTime::class, $statusResponseData->getDateTime());
    }

    private function createMockObjects(): array
    {
        return [
            $this->createMock(ShippingGatewayProxy::class),
        ];
    }
}

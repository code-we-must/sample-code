<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\ShipmentStatus\ShipmentStatus;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\BobGo\BobGoApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\BobGo\BobGoGetShipmentStatusInterface;
use App\Domain\Model\WayBill\WaybillStatus;
use App\Infrastructure\Domain\Model\Shipping\ProvidesShippingGatewayProxy;
use Symfony\Component\HttpFoundation\Request;

final class BobGoGetShipmentStatus implements BobGoGetShipmentStatusInterface
{
    use ProvidesShippingGatewayProxy;

    public function __construct(
        private readonly BobGoApiEndpointGeneratorInterface $apiEndpointGenerator,
    ) {
    }

    public function __invoke(ShipmentStatusRequestData $shipmentStatusRequestData): ShipmentStatusResponseData
    {
        $shippingNumber = $shipmentStatusRequestData->getShippingNumbers()[0];

        $response = $this->gatewayProxy->request(
            Request::METHOD_GET,
            $this->apiEndpointGenerator->getTrackingUrl(!$this->gatewayProxy->getConfigVar('testMode'))."?tracking_reference=$shippingNumber",
        );

        $statuses = [];
        foreach ($response as $trackingData) {
            $events = $trackingData['shipment_movement_events'];
            $providerStatus = WaybillStatus::PENDING;
            $isPaid = false;

            if ('' !== $events['delivered_time']) {
                $providerStatus = WaybillStatus::DELIVERED;
                $isPaid = true;
            } elseif ('' !== $events['collected_time']) {
                $providerStatus = WaybillStatus::SHIPPED;
            } elseif ('cancelled' === $trackingData['status']) {
                $providerStatus = WaybillStatus::CANCELED;
            }

            $statuses[] = new ShipmentStatus(
                $shippingNumber,
                $isPaid,
                $providerStatus->value,
            );
        }

        return new ShipmentStatusResponseData($statuses, new \DateTime());
    }
}

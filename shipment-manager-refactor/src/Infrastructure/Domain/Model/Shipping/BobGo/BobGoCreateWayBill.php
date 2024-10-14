<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Order\OrderShippingData;
use App\Domain\Model\Shipping\BobGo\BobGoApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\BobGo\BobGoCreateWayBillInterface;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\ProvidesShippingGatewayProxy;
use Symfony\Component\HttpFoundation\Request;

final class BobGoCreateWayBill implements BobGoCreateWayBillInterface
{
    use ProvidesShippingGatewayProxy;

    public function __construct(
        private readonly BobGoApiEndpointGeneratorInterface $apiEndpointGenerator,
        private readonly BobGoShipmentsPayloadAssembler $payloadAssembler,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(OrderShippingData $data): CreateWayBillResponseData
    {
        /**
         * @var array $settings {
         *            defaultSenderAddressId: string,
         *            defaultServiceCode: string,
         *            defaultLength: int,
         *            defaultWidth: int,
         *            defaultHeight: int,
         *            defaultWeight: int,
         *            preparationTime: int,
         *            }
         */
        $settings = $this->gatewayProxy->getProviderSettings()->getSettings();
        $payload = ($this->payloadAssembler)(
            $data,
            $settings,
            $this->gatewayProxy->getSenderAddress($settings),
        );

        $response = $this->gatewayProxy->request(
            Request::METHOD_POST,
            $this->apiEndpointGenerator->getShipmentsUrl(!$this->gatewayProxy->getConfigVar('testMode')),
            [
                'json' => $payload,
            ],
        );

        return new CreateWayBillResponseData($response['tracking_reference'], $response);
    }
}

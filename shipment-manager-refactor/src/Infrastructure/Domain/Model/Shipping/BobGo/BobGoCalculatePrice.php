<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Shipping\BobGo\BobGoApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\BobGo\BobGoCalculatePriceInterface;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationRequestData;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Infrastructure\Domain\Model\Shipping\ProvidesShippingGatewayProxy;
use Symfony\Component\HttpFoundation\Request;

final class BobGoCalculatePrice implements BobGoCalculatePriceInterface
{
    use ProvidesShippingGatewayProxy;

    public function __construct(
        private readonly BobGoApiEndpointGeneratorInterface $apiEndpointGenerator,
        private readonly BobGoRatesPayloadAssembler $ratesPayloadAssembler,
    ) {
    }

    public function __invoke(
        ShippingPriceEstimationRequestData $data,
    ): ShippingPriceEstimationResponseData {
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
        $payload = ($this->ratesPayloadAssembler)(
            $data,
            $settings,
            $this->gatewayProxy->getSenderAddress($settings),
        );

        $response = $this->gatewayProxy->request(
            Request::METHOD_POST,
            $this->apiEndpointGenerator->getRatesUrl(!$this->gatewayProxy->getConfigVar('testMode')),
            [
                'json' => $payload,
            ]
        );

        $price = $response['provider_rate_requests'][0]['responses'][0]['rate_amount'] ?? null;
        if (null === $price) {
            throw new \Exception('Unable to parse price.');
        }

        return new ShippingPriceEstimationResponseData($price);
    }
}

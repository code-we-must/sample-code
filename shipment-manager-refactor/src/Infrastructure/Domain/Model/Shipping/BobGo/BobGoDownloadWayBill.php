<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Shipping\BobGo\BobGoApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\BobGo\BobGoDownloadWayBillInterface;
use App\Domain\Model\WayBill\DownloadWayBillRequestData;
use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\ProvidesShippingGatewayProxy;
use Symfony\Component\HttpFoundation\Request;

final class BobGoDownloadWayBill implements BobGoDownloadWayBillInterface
{
    use ProvidesShippingGatewayProxy;

    public function __construct(
        private readonly BobGoApiEndpointGeneratorInterface $apiEndpointGenerator,
        private readonly BobGoWayBillPayloadAssembler $wayBillPayloadAssembler,
    ) {
    }

    public function __invoke(DownloadWayBillRequestData $downloadWayBillRequestData): DownloadWayBillResponseData
    {
        $payload = ($this->wayBillPayloadAssembler)($downloadWayBillRequestData->getShippingNumber());

        // Get PDF URL
        $response = $this->gatewayProxy->request(
            Request::METHOD_GET,
            $this->apiEndpointGenerator->getWaybillUrl(!$this->gatewayProxy->getConfigVar('testMode')).'?'.http_build_query($payload),
        );

        // Download PDF
        $fileContent = $this->gatewayProxy->getClient()->request(
            Request::METHOD_GET,
            $response['download_url'],
        )->getContent(false);

        return new DownloadWayBillResponseData('pdf', $fileContent);
    }
}

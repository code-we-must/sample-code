<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Shipping\BobGo\BobGoApiEndpointGeneratorInterface;

final class BobGoApiEndpointGenerator implements BobGoApiEndpointGeneratorInterface
{
    private const PRODUCTION_DOMAIN = 'api.bobgo.co.za';
    private const SANDBOX_DOMAIN = 'api.sandbox.bobgo.co.za';

    private const METHOD_RATES = 'rates';
    private const METHOD_SHIPMENTS = 'shipments';
    private const METHOD_WAYBILL = 'shipments/waybill';
    private const METHOD_WEBHOOKS = 'webhooks';
    private const METHOD_TRACKING = 'tracking';

    public function getRatesUrl(bool $isProduction): string
    {
        return $this->getUrl($isProduction, self::METHOD_RATES);
    }

    public function getShipmentsUrl(bool $isProduction): string
    {
        return $this->getUrl($isProduction, self::METHOD_SHIPMENTS);
    }

    public function getWaybillUrl(bool $isProduction): string
    {
        return $this->getUrl($isProduction, self::METHOD_WAYBILL);
    }

    public function getWebhooksUrl(bool $isProduction): string
    {
        return $this->getUrl($isProduction, self::METHOD_WEBHOOKS);
    }

    public function getTrackingUrl(bool $isProduction): string
    {
        return $this->getUrl($isProduction, self::METHOD_TRACKING);
    }

    private function getUrl(bool $isProduction, string $method): string
    {
        return 'https://'.($isProduction ? self::PRODUCTION_DOMAIN : self::SANDBOX_DOMAIN)."/v2/$method";
    }
}

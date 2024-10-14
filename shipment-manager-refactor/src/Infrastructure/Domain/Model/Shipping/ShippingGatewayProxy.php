<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping;

use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\SenderAddress\SenderAddress;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Allows shipping service classes (i.e. BobGoCalculatePrice) to have access to AbstractShippingGateway methods and properties.
 */
final class ShippingGatewayProxy implements ShippingGatewayProxyInterface
{
    private AbstractShippingGateway $gateway;

    public function setGateway(AbstractShippingGateway $gateway): void
    {
        $this->gateway = $gateway;
    }

    public function getClient(): HttpClientInterface
    {
        return $this->getReflectionMethod('getGateway')
            ->invoke($this->gateway);
    }

    public function getConfigVar(
        string $name,
        mixed $default = null,
    ): mixed {
        return $this->getReflectionProperty('config')
            ->getValue($this->gateway)[$name] ?? $default;
    }

    public function getProviderSettings(): ProviderSettings
    {
        return $this->getReflectionProperty('providerSettings')
            ->getValue($this->gateway);
    }

    public function request(
        string $method,
        string $url,
        array $data = []
    ): mixed {
        return $this->getReflectionMethod(__FUNCTION__)
            ->invokeArgs($this->gateway, [
                $method,
                $url,
                $data,
            ]);
    }

    public function getSenderAddress(array $settings): ?SenderAddress
    {
        return $this->getReflectionMethod(__FUNCTION__)
            ->invokeArgs($this->gateway, [$settings]);
    }

    private function getReflectionProperty(string $property): ReflectionProperty
    {
        return (new ReflectionClass($this->gateway))
            ->getProperty($property);
    }

    private function getReflectionMethod(string $method): \ReflectionMethod
    {
        return new \ReflectionMethod(get_class($this->gateway), $method);
    }
}

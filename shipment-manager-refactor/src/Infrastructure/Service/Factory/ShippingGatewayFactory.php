<?php

declare(strict_types=1);

namespace App\Infrastructure\Service\Factory;

use App\Application\Service\Factory\ShippingGatewayFactoryInterface;
use App\Domain\Exception\InvalidProviderException;
use App\Domain\Model\Provider\Provider;
use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\Shipping\ShippingGatewayInterface;
use Http\Discovery\Exception\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ShippingGatewayFactory implements ShippingGatewayFactoryInterface
{
    public function __construct(
        private readonly ServiceLocator $shippingProvidersLocator,
    ) {
    }

    /**
     * @throws InvalidProviderException
     */
    public function getGateway(
        ProviderSettings $providerSettings,
        ?array $customSettings = null,
    ): ShippingGatewayInterface {
        try {
            $gateway = $this->getGatewayFromProviderName($providerSettings->getProvider());
        } catch (\ValueError|NotFoundException|ContainerExceptionInterface $e) {
            throw new InvalidProviderException('Invalid provider name.');
        }

        $gateway->setProviderSettings($providerSettings, $customSettings);

        return $gateway;
    }

    /**
     * @throws \ValueError|NotFoundException|ContainerExceptionInterface
     */
    public function getGatewayFromProviderName(string $providerName): ShippingGatewayInterface
    {
        $providerName = Provider::from($providerName)->name;
        $className = "App\Infrastructure\Domain\Model\Shipping\\{$providerName}\\{$providerName}ShippingGateway";

        return $this->shippingProvidersLocator->get($className);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Service\Factory;

use App\Application\Service\Encryption\ProvidersEncryptorInterface;
use App\Application\Service\Factory\ShippingGatewayFactoryInterface;
use App\Application\Service\TranslatorInterface;
use App\Application\Shared\OrdersHelper;
use App\Domain\Exception\InvalidProviderException;
use App\Domain\Model\Provider\Provider;
use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\SenderAddress\SenderAddressRepositoryInterface;
use App\Domain\Model\Shipping\Cvc\CvcApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\Cvc\CvcShippingOfficesRepositoryInterface;
use App\Domain\Model\Shipping\Dhl\DhlShippingOfficesRepositoryInterface;
use App\Domain\Model\Shipping\Econt\EcontShippingOfficesRepositoryInterface;
use App\Domain\Model\Shipping\FedEx\FedExShippingOfficesRepositoryInterface;
use App\Domain\Model\Shipping\Leopards\LeopardsApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\Pargo\PargoShippingOfficesRepositoryInterface;
use App\Domain\Model\Shipping\ShippingGatewayInterface;
use App\Domain\Model\Shipping\SkyNet\SkyNetShippingOfficesRepositoryInterface;
use App\Domain\Model\Shipping\Speedy\SpeedyShippingOfficesRepositoryInterface;
use App\Domain\Model\Tenant\AppManagerInterface;
use App\Domain\Model\Tenant\TenantRepositoryInterface;
use App\Domain\Model\Warehouse\WarehouseRepositoryInterface;
use App\Infrastructure\Domain\Model\Shipping\BlueEX\BlueEXShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Borzo\BorzoShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Cvc\CvcShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Delhivery\DelhiveryShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Dhl\DhlShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Econt\EcontShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\FedEx\FedExShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\InOut\InOutShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\JnT\JnTShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Kwik\KwikShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Lalamove\LalamoveShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Leopards\LeopardsShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Pargo\PargoShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Shipdeo\ShipdeoApiEndpointGenerator;
use App\Infrastructure\Domain\Model\Shipping\Shipdeo\ShipdeoShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\SkyNet\SkyNetShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Sonic\SonicShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Speedy\SpeedyShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\TheCourierGuy\TheCourierGuyShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\UParcel\UParcelShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Ups\UpsShippingGateway;
use App\Infrastructure\Exception\TenantIdException;
use App\Infrastructure\Exception\TenantProvidersException;
use App\Infrastructure\Service\Econt\CreateLabelRequestDataMapperInterface;
use App\Infrastructure\Service\Econt\CreateLabelRequestParamsMapperInterface;
use App\Infrastructure\Service\Econt\EcontRequestErrorHandler;
use App\Infrastructure\Service\JnT\JnTRequestErrorHandler;
use App\Infrastructure\Service\Speedy\CalculateRequestDataMapperInterface as SpeedyCalculateRequestDataMapperInterface;
use App\Infrastructure\Service\Speedy\CreateShipmentRequestDataMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ShippingGatewayFactory implements ShippingGatewayFactoryInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProvidersEncryptorInterface $encryptor,
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly SenderAddressRepositoryInterface $senderAddressRepository,
        private readonly TranslatorInterface $translator,
        private readonly EcontShippingOfficesRepositoryInterface $econtShippingOfficesRepository,
        private readonly SpeedyShippingOfficesRepositoryInterface $speedyShippingOfficesRepository,
        private readonly SkyNetShippingOfficesRepositoryInterface $skyNetShippingOfficesRepository,
        private readonly DhlShippingOfficesRepositoryInterface $dhlShippingOfficesRepository,
        private readonly FedExShippingOfficesRepositoryInterface $fedExShippingOfficesRepository,
        private readonly SerializerInterface $serializer,
        private readonly CreateLabelRequestDataMapperInterface $econtCreateLabelRequestDataMapper,
        private readonly CreateLabelRequestParamsMapperInterface $econtCreateLabelParamsMapper,
        private readonly SpeedyCalculateRequestDataMapperInterface $speedyCalculateRequestDataMapper,
        private readonly CreateShipmentRequestDataMapper $speedyCreateShipmentRequestDataMapper,
        private readonly EcontRequestErrorHandler $econtRequestErrorHandler,
        private readonly JnTRequestErrorHandler $jntRequestErrorHandler,
        private readonly CvcApiEndpointGeneratorInterface $endpointGenerator,
        private readonly CvcShippingOfficesRepositoryInterface $cvcShippingOfficesRepository,
        private readonly WarehouseRepositoryInterface $warehouseRepository,
        private readonly PargoShippingOfficesRepositoryInterface $pargoShippingOfficesRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly AppManagerInterface $appManager,
        private readonly OrdersHelper $ordersHelper,
        private readonly ShipdeoApiEndpointGenerator $shipdeoApiEndpointGenerator,
        private readonly LeopardsApiEndpointGeneratorInterface $leopardsApiEndpointGenerator,
    ) {
    }

    /**
     * @throws TenantIdException|InvalidProviderException|TenantProvidersException
     */
    public function getGateway(
        ProviderSettings $providerSettings,
        ?array $customSettings = null,
    ): ShippingGatewayInterface {
        $gateway = $this->getGatewayFromProviderName($providerSettings->getProvider());
        $gateway->setProviderSettings($providerSettings, $customSettings);

        return $gateway;
    }

    /**
     * @throws TenantIdException
     * @throws InvalidProviderException
     */
    public function getGatewayFromProviderName(string $providerName): ShippingGatewayInterface
    {
        /** @var Request $request */
        $request = $this->requestStack->getCurrentRequest();

        return match ($providerName) {
            Provider::TheCourierGuy->value => new TheCourierGuyShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository),
            Provider::Borzo->value => new BorzoShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository),
            Provider::InOut->value => new InOutShippingGateway($request, $this->encryptor, $this->client, $this->logger),
            Provider::Econt->value => new EcontShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->translator, $this->econtShippingOfficesRepository, $this->econtCreateLabelParamsMapper, $this->econtCreateLabelRequestDataMapper, $this->econtRequestErrorHandler, $this->ordersHelper),
            Provider::Speedy->value => new SpeedyShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->translator, $this->speedyShippingOfficesRepository, $this->speedyCalculateRequestDataMapper, $this->speedyCreateShipmentRequestDataMapper),
            Provider::SkyNet->value => new SkyNetShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->serializer, $this->skyNetShippingOfficesRepository),
            Provider::Kwik->value => new KwikShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository),
            Provider::Lalamove->value => new LalamoveShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository),
            Provider::Dhl->value => new DhlShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->translator, $this->dhlShippingOfficesRepository),
            Provider::BlueEX->value => new BlueEXShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository),
            Provider::Cvc->value => new CvcShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->cvcShippingOfficesRepository, $this->senderAddressRepository, $this->translator, $this->endpointGenerator),
            Provider::Delhivery->value => new DelhiveryShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->warehouseRepository),
            Provider::Sonic->value => new SonicShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->warehouseRepository),
            Provider::Pargo->value => new PargoShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->pargoShippingOfficesRepository),
            Provider::UParcel->value => new UParcelShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->appManager, $this->tenantRepository),
            Provider::Ups->value => new UpsShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository),
            Provider::FedEx->value => new FedExShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->tenantRepository, $this->fedExShippingOfficesRepository, $this->senderAddressRepository),
            Provider::Shipdeo->value => new ShipdeoShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->shipdeoApiEndpointGenerator, $this->senderAddressRepository),
            Provider::JnT->value => new JnTShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->senderAddressRepository, $this->translator, $this->jntRequestErrorHandler),
            Provider::Leopards->value => new LeopardsShippingGateway($request, $this->encryptor, $this->client, $this->logger, $this->leopardsApiEndpointGenerator),
            default => throw new InvalidProviderException('Invalid provider name.'),
        };
    }
}

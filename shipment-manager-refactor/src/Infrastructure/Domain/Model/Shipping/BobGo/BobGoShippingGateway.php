<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Application\Service\Encryption\ProvidersEncryptorInterface;
use App\Domain\Model\Error\ConstraintViolation;
use App\Domain\Model\Order\OrderShippingData;
use App\Domain\Model\Provider\ProviderSettingsCredentials;
use App\Domain\Model\SenderAddress\SenderAddress;
use App\Domain\Model\SenderAddress\SenderAddressRepositoryInterface;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\BobGo\BobGoCalculatePriceInterface;
use App\Domain\Model\Shipping\BobGo\BobGoCreateWayBillInterface;
use App\Domain\Model\Shipping\BobGo\BobGoDownloadWayBillInterface;
use App\Domain\Model\Shipping\BobGo\BobGoGetShipmentStatusInterface;
use App\Domain\Model\Shipping\BobGo\BobGoShippingGatewayInterface;
use App\Domain\Model\Shipping\BobGo\BobGoValidateCredentialsInterface;
use App\Domain\Model\Shipping\CourierOfficeFiltersInterface;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationRequestData;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Domain\Model\Tenant\AppId;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Domain\Model\WayBill\DownloadWayBillRequestData;
use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\AbstractShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\ShippingGatewayProxy;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @see https://api-docs.bob.co.za/bobgo
 */
class BobGoShippingGateway extends AbstractShippingGateway implements BobGoShippingGatewayInterface
{
    public function __construct(
        Request $request,
        ProvidersEncryptorInterface $encryptor,
        HttpClientInterface $client,
        LoggerInterface $logger,

        private readonly SenderAddressRepositoryInterface $senderAddressRepository,
        private readonly ShippingGatewayProxy $gatewayProxy,
        private readonly BobGoValidateCredentialsInterface $validateCredentials,
        private readonly BobGoCalculatePriceInterface $calculatePrice,
        private readonly BobGoCreateWayBillInterface $createWayBill,
        private readonly BobGoDownloadWayBillInterface $downloadWayBill,
        private readonly BobGoGetShipmentStatusInterface $getShipmentStatus,
    ) {
        parent::__construct($request, $encryptor, $client, $logger);

        $this->gatewayProxy->setGateway($this);
        $this->validateCredentials->setGatewayProxy($this->gatewayProxy);
        $this->calculatePrice->setGatewayProxy($this->gatewayProxy);
        $this->createWayBill->setGatewayProxy($this->gatewayProxy);
        $this->downloadWayBill->setGatewayProxy($this->gatewayProxy);
        $this->getShipmentStatus->setGatewayProxy($this->gatewayProxy);
    }

    public function validateCredentials(ProviderSettingsCredentials $credentials): ?ConstraintViolation
    {
        $response = null;

        try {
            ($this->validateCredentials)($credentials->getToken());
        } catch (\Exception $e) {
            $response = new ConstraintViolation($e->getMessage());
        }

        return $response;
    }

    public function calculatePrice(ShippingPriceEstimationRequestData $data): ShippingPriceEstimationResponseData|ConstraintViolation
    {
        try {
            $response = ($this->calculatePrice)($data);
        } catch (\Exception $e) {
            $response = new ConstraintViolation($e->getMessage());
        }

        return $response;
    }

    public function createWayBill(OrderShippingData $orderShippingData): CreateWayBillResponseData|ConstraintViolation
    {
        try {
            $response = ($this->createWayBill)($orderShippingData);
        } catch (\Exception $e) {
            $response = new ConstraintViolation($e->getMessage());
        }

        return $response;
    }

    public function getCourierOffices(CourierOfficeFiltersInterface $filters): array|ConstraintViolation
    {
        return [];
    }

    public function downloadWayBill(DownloadWayBillRequestData $downloadWayBillRequestData): DownloadWayBillResponseData|ConstraintViolation
    {
        try {
            $response = ($this->downloadWayBill)($downloadWayBillRequestData);
        } catch (\Exception $e) {
            $response = new ConstraintViolation($e->getMessage());
        }

        return $response;
    }

    public function getShipmentStatus(ShipmentStatusRequestData $shipmentStatusRequestData): ShipmentStatusResponseData|ConstraintViolation
    {
        try {
            $response = ($this->getShipmentStatus)($shipmentStatusRequestData);
        } catch (\Exception $e) {
            $response = new ConstraintViolation($e->getMessage());
        }

        return $response;
    }

    protected function getClientOptions(): array
    {
        return [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$this->getConfigVar('token')}",
            ],
        ];
    }

    protected function getProviderName(): string
    {
        return AppId::BobGo->value;
    }

    protected function handleRequestException(ExceptionInterface $ex): ConstraintViolation
    {
        return new ConstraintViolation($ex->getMessage());
    }

    protected function getSenderAddress(array $settings): ?SenderAddress
    {
        return array_key_exists('defaultSenderAddressId', $settings) ?
            $this->senderAddressRepository->findOneById($settings['defaultSenderAddressId']) :
            $this->senderAddressRepository->findDefaultAddress();
    }
}

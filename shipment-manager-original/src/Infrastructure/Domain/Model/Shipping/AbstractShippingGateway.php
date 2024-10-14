<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping;

use App\Application\Service\Encryption\ProvidersEncryptorInterface;
use App\Domain\Model\Error\ConstraintViolation;
use App\Domain\Model\Error\ConstraintViolationList;
use App\Domain\Model\Order\OrderShippingData;
use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\CashOnDeliveryPolicy;
use App\Domain\Model\Tenant\Tenant;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Domain\Model\WayBill\WaybillStatus;
use App\Infrastructure\Exception\GatewayErrorException;
use App\Infrastructure\Exception\TenantIdException;
use Psr\Log\LoggerInterface;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractShippingGateway
{
    protected array $config;
    protected string $tenantId;
    protected readonly LoggerInterface $logger;
    protected HttpClientInterface $client;
    protected ProviderSettings $providerSettings;
    protected ?array $customSettings;
    protected string $defaultFileType = 'application/pdf';

    /**
     * @throws TenantIdException
     */
    public function __construct(
        Request $request,
        ProvidersEncryptorInterface $encryptor,
        HttpClientInterface $client,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;

        /** @var ?Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        if (null === $tenant) {
            throw new TenantIdException('Tenant is not found');
        }

        $this->tenantId = $tenant->getId();

        $config = $tenant->getApps() ? $encryptor->decrypt($tenant->getApps()) : [];

        $iterator = new RecursiveIteratorIterator(
            iterator: new RecursiveArrayIterator($config),
            mode: RecursiveIteratorIterator::SELF_FIRST
        );

        $provider = strtolower($this->getProviderName());
        $neededConfig = [];
        foreach ($iterator as $providerName => $providerConfig) {
            if ($provider === strtolower((string) $providerName)) {
                $neededConfig = $providerConfig;
                break;
            }
        }

        $this->config = $neededConfig;
        $this->client = $client;
    }

    protected function request(string $method, string $url, array $data = []): array|ConstraintViolation
    {
        try {
            $gateway = $this->getGateway();
        } catch (GatewayErrorException) {
            return new ConstraintViolation('Invalid gateway configuration.');
        }

        try {
            $this->logger->debug('Gateway request', [
                'method' => $method,
                'url' => $url,
                'data' => $data,
                'config' => $this->config,
            ]);

            return $gateway->request($method, $url, $data)->toArray();
        } catch (ClientExceptionInterface|ServerExceptionInterface $e) {
            $this->logger->critical('Gateway response error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'response' => $e->getResponse()->toArray(false), ],
            );

            return $this->handleRequestException($e);
        } catch (ExceptionInterface $e) {
            $this->logger->critical('Gateway request error', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'exception' => $e,
            ]);

            return $this->handleRequestException($e);
        }
    }

    /**
     * @throw GatewayErrorException
     */
    protected function getGateway(): HttpClientInterface
    {
        return $this->client->withOptions($this->getClientOptions());
    }

    protected function getConfigVar(string $name, mixed $default = null): mixed
    {
        return $this->config[$name] ?? $default;
    }

    public function setProviderSettings(
        ProviderSettings $providerSettings,
        ?array $customSettings = null,
    ): void {
        $this->providerSettings = $providerSettings;
        $this->customSettings = $customSettings;
    }

    public function getShipmentStatus(ShipmentStatusRequestData $shipmentStatusRequestData): ShipmentStatusResponseData|ConstraintViolation
    {
        return new ConstraintViolation('Method not implemented for provider.');
    }

    public function getStatusMapping(string|int $providerStatus): string
    {
        return WaybillStatus::defaultMapping()[$providerStatus] ?? '';
    }

    public function getCashOnDeliveryPolicy(): CashOnDeliveryPolicy
    {
        return CashOnDeliveryPolicy::NoPolicy;
    }

    public function isCashOnDeliveryAttemptedButNotAllowed(bool $isCashOnDelivery): bool
    {
        return $isCashOnDelivery && CashOnDeliveryPolicy::NotAllowed === $this->getCashOnDeliveryPolicy();
    }

    /**
     * @return array
     */
    public function createBulkWayBill(array $orderShippingDatas): array|ConstraintViolation
    {
        return new ConstraintViolation('To be implemented');
    }

    public function mapWayBills(array $mapWayBillRequestData): array|ConstraintViolationList
    {
        return [];
    }

    /**
     * @param OrderShippingData[] $orderShippingDatas
     *
     * @return string
     */
    public function mapOrderId(array $orderShippingDatas, CreateWayBillResponseData $wayBillResponse): ?string
    {
        $orderId = null;

        /** @var OrderShippingData $orderShippingData */
        foreach ($orderShippingDatas as $orderShippingData) {
            /** @var array{reference: string} */
            $shippingInfo = $wayBillResponse->getShippingInfo();
            if ($orderShippingData->getReference() === $shippingInfo['reference']) {
                $orderId = $orderShippingData->getOrderId();
                break;
            }
        }

        return $orderId;
    }

    abstract protected function getClientOptions(): array;

    abstract protected function getProviderName(): string;

    abstract protected function handleRequestException(ExceptionInterface $ex): ConstraintViolation;
}

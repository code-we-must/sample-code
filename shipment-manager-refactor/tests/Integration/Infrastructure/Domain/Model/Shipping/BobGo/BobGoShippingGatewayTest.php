<?php

namespace App\Tests\Integration\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Provider\ProviderSettingsCredentials;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\BobGo\BobGoShippingGatewayInterface;
use App\Domain\Model\ShippingMethod\PaymentMethod;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoShippingGateway;
use App\Infrastructure\Persistence\Connection\DoctrineTenantConnection;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Shared\Factory\BobGo\BobGoResponseFileFactory;
use App\Tests\Shared\Factory\OrderShipmentDataFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\ShippingAddressFactory;
use App\Tests\Shared\Factory\ShippingPriceEstimationDataFactory;
use App\Tests\Shared\Factory\TenantFactory;
use App\Tests\Shared\Factory\WayBillFactory;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BobGoShippingGatewayTest extends IntegrationTestCase
{
    private DoctrineTenantConnection $connection;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $tenant = TenantFactory::getTenant();
        $this->setTenantInRequest($tenant);
        $this->connection = $this->createDoctrineTenantConnection();
    }

    /**
     * @throws Exception
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    public function testValidateCredentialsSuccess(): void
    {
        $providerSettingsCredentials = new ProviderSettingsCredentials(token: 'valid-token');

        $result = $this->getGateway($this->getHttpClient([BobGoResponseFileFactory::getWebhooksSuccessResponseFile()]))
            ->validateCredentials($providerSettingsCredentials);

        $this->assertNull($result);
    }

    public function testCalculatePriceSuccess(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
        );
        $httpClient = $this->getHttpClient([BobGoResponseFileFactory::getRatesSuccessResponseFile()]);

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $this->getGateway($httpClient)
            ->calculatePrice($requestData);

        $this->assertInstanceOf(ShippingPriceEstimationResponseData::class, $result);
        $this->assertSame(249.25, $result->getPrice());
    }

    public function testCreateWayBillSuccess(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'ZAF',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 0,
            shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
            shippingPrice: 0,
            shippingPriceWithoutFees: 0,
            orderProductDetails: OrderShipmentDataFactory::getProductDetails(),
        );
        $httpClient = $this->getHttpClient([BobGoResponseFileFactory::getShipmentsSuccessResponseFile()]);

        /** @var CreateWayBillResponseData $result */
        $result = $this->getGateway($httpClient)
            ->createWayBill($orderShipmentData);

        $this->assertInstanceOf(CreateWayBillResponseData::class, $result);
        $this->assertSame('UASSPW7D', $result->getShippingNumber());
    }

    public function testDownloadWaybillSuccess(): void
    {
        $downloadRequestData = ProvidersFactory::getDownloadWayBillRequestData(
            wayBillNumber: 'UASSPW7D',
        );
        $httpClient = $this->getHttpClient([
            BobGoResponseFileFactory::getWaybillSuccessResponseFile(),
            BobGoResponseFileFactory::getPdfSuccessResponseFile(),
        ]);

        /** @var DownloadWayBillResponseData $result */
        $result = $this->getGateway($httpClient)
            ->downloadWayBill($downloadRequestData);

        $this->assertInstanceOf(DownloadWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::FILE_TYPE, $result->getType());
        $this->assertSame(WayBillFactory::FILE_CONTENT, $result->getContent());
    }

    public function testGetShipmentStatusSuccess(): void
    {
        $shipmentStatusRequestData = new ShipmentStatusRequestData(['UASSPW7D'], []);

        $httpClient = $this->getHttpClient([BobGoResponseFileFactory::getTrackingSuccessResponseFile()]);

        $result = $this->getGateway($httpClient)
            ->getShipmentStatus($shipmentStatusRequestData);

        $this->assertInstanceOf(ShipmentStatusResponseData::class, $result);
        $this->assertFalse($result->getData()[0]->getPaid());
        $this->assertSame('pending', $result->getData()[0]->getProviderStatus());
    }

    private function getGateway(HttpClientInterface $httpClient): BobGoShippingGatewayInterface
    {
        /** @var BobGoShippingGateway $gateway */
        $gateway = self::getContainer()->get(BobGoShippingGateway::class);

        (new \ReflectionClass(BobGoShippingGateway::class))
            ->getProperty('client')
            ->setValue($gateway, $httpClient);

        $gateway->setProviderSettings(ProvidersFactory::getProviderSettingsForBobGo());

        return $gateway;
    }

    private function getHttpClient(array $responsePaths): HttpClientInterface
    {
        // Use NativeHttpClient to test live requests.
        return new MockHttpClient(array_map(function (string $path): MockResponse {
            return $this->getMockResponseFromFile($path, str_ends_with($path, '_failure.json') ? 401 : 200);
        }, $responsePaths));
    }
}

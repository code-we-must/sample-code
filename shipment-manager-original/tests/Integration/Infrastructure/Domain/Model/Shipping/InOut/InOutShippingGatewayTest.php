<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Domain\Model\Shipping\InOut;

use App\Application\Service\Encryption\ProvidersEncryptorInterface;
use App\Domain\Model\Error\ConstraintViolation;
use App\Domain\Model\Error\ConstraintViolationList;
use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\Provider\ProviderSettingsCredentials;
use App\Domain\Model\ShipmentStatus\ShipmentStatus;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\CourierOfficeFiltersInterface;
use App\Domain\Model\Shipping\InOut\InOutCourierOfficeFilters;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Domain\Model\WayBill\MapWayBillRequestData;
use App\Infrastructure\Domain\Model\Shipping\CompanyData;
use App\Infrastructure\Domain\Model\Shipping\CourierData;
use App\Infrastructure\Domain\Model\Shipping\InOut\InOutShippingGateway;
use App\Infrastructure\Exception\TenantIdException;
use App\Infrastructure\Exception\TenantProvidersException;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Shared\Factory\InOutFactory;
use App\Tests\Shared\Factory\OrderShipmentDataFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\ShippingPriceEstimationDataFactory;
use App\Tests\Shared\Factory\TenantFactory;
use App\Tests\Shared\Factory\WayBillFactory;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class InOutShippingGatewayTest extends IntegrationTestCase
{
    private const ESTIMATE_PRICE_URI = 'shipment-price';
    private const GET_COMPANIES_URI = 'get-user-companies';
    private const GET_COURIERS_URI = 'couriers/{companyId}';
    private const GET_COURIER_OFFICES_URI = 'offices-by-courier/{courierId}';
    private const CREATE_WAY_BILL_URI = 'createAWB';
    private const DOWNLOAD_WAY_BILL_URI = 'print/{shippingNumber}';

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = TenantFactory::getTenant();

        $this->setTenantInRequest($tenant);
    }

    public function testValidateCredentialsWorks(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $httpClientMock = new MockHttpClient();

        $gateway = $this->getGateway($httpClientMock);

        $result = $gateway->validateCredentials(new ProviderSettingsCredentials(
            token: 'test',
        ));

        $this->assertNull($result);
    }

    public function testValidateCredentialsReturnConstraintViolation(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $httpClientMock = new MockHttpClient($this->getMockResponse(
            InOutFactory::GET_COMPANIES_JSON_FILE_NAME,
            400,
        ));

        $gateway = $this->getGateway($httpClientMock);

        $result = $gateway->validateCredentials(new ProviderSettingsCredentials(
            token: 'invalid',
        ));

        $this->assertInstanceOf(ConstraintViolation::class, $result);
        $this->assertSame('common', $result->getPath());
        $this->assertSame('Invalid credentials', $result->getMessage());
    }

    public function testCalculatePriceWorks(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData();
        $providerSettings = ProvidersFactory::getProviderSettings();

        $mockResponse = $this->getMockResponseFromFile(InOutFactory::PRICE_ESTIMATE_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock, $providerSettings);

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        /** @var array{companyId: int|string, courierId: int|string, openPackage: bool, returnDocs: ?int, saturdayDelivery: bool, isFragile: bool} $settings */
        $settings = $providerSettings->getSettings();

        $expectedJson = [
            'weight' => $requestData->getWeight() / 1000,
            'codAmount' => $requestData->getCodAmount(),
            'insuranceAmount' => $requestData->getInsuranceAmount(),
            'openPackage' => $settings['openPackage'],
            'toOffice' => $requestData->isToOffice(),
            'currency' => $requestData->getCurrency(),
            'companyId' => $settings['companyId'],
            'courierId' => $settings['courierId'],
            'returnDocs' => $settings['returnDocs'],
            'saturdayDelivery' => $settings['saturdayDelivery'],
        ];

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::ESTIMATE_PRICE_URI, $mockResponse->getRequestUrl());
        $this->assertSame([], array_diff($expectedJson, $this->getMockRequestBody($mockResponse)));

        $this->assertInstanceOf(ShippingPriceEstimationResponseData::class, $result);
        $this->assertSame(InOutFactory::ESTIMATE_PRICE_FLOAT, $result->getPrice());
    }

    public function testCalculatePriceWithEmptyConfigTokenReturnsError(): void
    {
        $tenant = TenantFactory::getTenant(apps: null);
        $this->setTenantInRequest($tenant);

        /** @var string $body */
        $body = json_encode(['error' => 'invalid request']);
        $mockResponse = $this->getMockResponse($body, 500);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        $requestData = ShippingPriceEstimationDataFactory::getData();

        $result = $gateway->calculatePrice($requestData);

        $this->assertInstanceOf(ConstraintViolation::class, $result);
        $this->assertSame('common', $result->getPath());
        $this->assertSame('Invalid gateway configuration.', $result->getMessage());
    }

    public function testCalculatePriceReturnError(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_INVALID_INOUT_TOKEN);

        $this->setTenantInRequest($tenant);

        $requestData = ShippingPriceEstimationDataFactory::getData();

        /** @var string $body */
        $body = json_encode(['error' => 'invalid request']);
        $mockResponse = $this->getMockResponse($body, 500);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        /** @var ConstraintViolation $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertInstanceOf(ConstraintViolation::class, $result);
        $this->assertSame('common', $result->getPath());
        $this->assertSame('Gateway invalid request', $result->getMessage());
    }

    public function testGetCompaniesWorks(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $mockResponse = $this->getMockResponseFromFile(InOutFactory::GET_COMPANIES_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        /** @var CompanyData[] $result */
        $result = $gateway->getCompanies();

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::GET_COMPANIES_URI, $mockResponse->getRequestUrl());
        $this->assertArrayNotHasKey('body', $mockResponse->getRequestOptions());

        $this->assertContainsOnlyInstancesOf(CompanyData::class, $result);
    }

    public function testGetCouriersWorks(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        /** @var string $body */
        $body = json_encode(['error' => 'invalid request']);
        $mockResponse = $this->getMockResponse($body, 500);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        /** @var CompanyData[] $companies */
        $companies = $gateway->getCompanies();

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::GET_COMPANIES_URI, $mockResponse->getRequestUrl());
        $this->assertArrayNotHasKey('body', $mockResponse->getRequestOptions());

        foreach ($companies as $company) {
            $courierRoute = str_replace('{companyId}', (string) $company->getId(), self::GET_COURIERS_URI);

            $jsonResponseFile = str_replace('{companyId}', (string) $company->getId(), InOutFactory::GET_COURIERS_JSON_FILE_NAME);

            $mockResponse = $this->getMockResponseFromFile($jsonResponseFile);

            $httpClientMock = new MockHttpClient($mockResponse);

            $gateway = $this->getGateway($httpClientMock);

            /** @var CourierData[] $couriers */
            $couriers = $gateway->getCouriers($company->getId());

            $this->assertSame('GET', $mockResponse->getRequestMethod());
            $this->assertStringEndsWith($courierRoute, $mockResponse->getRequestUrl());
            $this->assertArrayNotHasKey('body', $mockResponse->getRequestOptions());

            $this->assertContainsOnlyInstancesOf(CourierData::class, $couriers);
        }
    }

    private function assertWaybill(MockResponse $awbMockResponse): void
    {
        $providerSettings = ProvidersFactory::getProviderSettings();
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();

        /** @var array{companyId: int|string, courierId: int|string, openPackage: bool, returnDocs: ?int, saturdayDelivery: bool, isFragile: bool} $settings */
        $settings = $providerSettings->getSettings();

        $productDescription = mb_substr($orderShipmentData->getDescription(), 0, 1000);

        $contactName = implode(' ', [
            $orderShipmentData->getShippingAddress()->getFirstName(),
            $orderShipmentData->getShippingAddress()->getLastName(),
        ]);

        $expectedJson = [
            'testMode' => InOutFactory::TEST_MODE_DEFAULT,
            'senderId' => $settings['companyId'],
            'courierId' => $settings['courierId'],
            'waybillAvailableDate' => date('Y-m-d', strtotime('+1day')),
            'serviceName' => InOutFactory::SHIPMENT_SERVICE_NAME_DEFAULT,
            'recipient' => [
                'name' => $contactName,
                'countryIsoCode' => Countries::getAlpha2Code($providerSettings->getCountry()),
                'region' => $orderShipmentData->getShippingAddress()->getArea(),
                'cityName' => $orderShipmentData->getShippingAddress()->getCity(),
                'zipCode' => '1000',
                'streetName' => 'to office: '.$orderShipmentData->getShippingAddress()->getAddress(),
                'addressText' => $orderShipmentData->getShippingAddress()->getAddressAdditions(),
                'contactPerson' => $contactName,
                'phoneNumber' => '0883456789',
                'email' => $orderShipmentData->getCustomerEmail(),
            ],
            'awb' => [
                'parcels' => $orderShipmentData->getParcels(),
                'envelopes' => 0, // @TODO We do not send envelopes for now
                'totalWeight' => max(InOutFactory::CREATE_WAY_BILL_MIN_PRODUCTS_WEIGHT, $orderShipmentData->getProductsWeight() / 1000),
                'declaredValue' => 0, // $orderShipmentData->getTotalPrice() / 100
                'bankRepayment' => 0,
                'otherRepayment' => '',
                'observations' => '',
                'openPackage' => $orderShipmentData->hasOpenCheck() ?: $settings['openPackage'],
                'referenceNumber' => $orderShipmentData->getReference(),
                'products' => $productDescription,
                'fragile' => $orderShipmentData->isFragile() ?: $settings['isFragile'],
                'productsInfo' => $orderShipmentData->getNotes(), // Additional product information.
                'piecesInPack' => $orderShipmentData->getProductsQuantity(),
                'saturdayDelivery' => $settings['saturdayDelivery'],
            ],
            'returnLabel' => [
                'nDaysValid' => 0,
            ],
        ];

        $this->assertSame('POST', $awbMockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::CREATE_WAY_BILL_URI, $awbMockResponse->getRequestUrl());
        $this->assertSame($expectedJson, $this->getMockRequestBody($awbMockResponse));
    }

    public function testCreateWayBillWorks(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $orderShipmentData = OrderShipmentDataFactory::getCacheData();

        $cityMockResponse = $this->getMockResponseFromFile(InOutFactory::GET_CITIES_JSON_FILE_NAME);
        $awbMockResponse = $this->getMockResponseFromFile(InOutFactory::CREATE_WAY_BILL_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient([
            $cityMockResponse,
            $awbMockResponse,
        ]);

        $gateway = $this->getGateway($httpClientMock);

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertWaybill($awbMockResponse);

        $this->assertInstanceOf(CreateWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::SHIPPING_NUMBER_FROM_RESPONSE, $result->getShippingNumber());
    }

    public function testCreateWaybillGetCitiesFail(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $orderShipmentData = OrderShipmentDataFactory::getCacheData();

        $cityMockResponse = $this->getMockResponseFromFile(InOutFactory::GET_CITIES_JSON_FILE_NAME, 422);
        $awbMockResponse = $this->getMockResponseFromFile(InOutFactory::CREATE_WAY_BILL_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient([
            $cityMockResponse,
            $awbMockResponse,
        ]);

        $gateway = $this->getGateway($httpClientMock);

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertWaybill($awbMockResponse);

        $this->assertInstanceOf(CreateWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::SHIPPING_NUMBER_FROM_RESPONSE, $result->getShippingNumber());
    }

    public function testCreateBulkWayBill(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();

        $cityMockResponse = $this->getMockResponseFromFile(InOutFactory::GET_CITIES_JSON_FILE_NAME);
        $awbMockResponse = $this->getMockResponseFromFile(InOutFactory::CREATE_WAY_BILL_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient([
            $cityMockResponse,
            $awbMockResponse,
        ]);

        $gateway = $this->getGateway($httpClientMock);

        /** @var CreateWayBillResponseData[] $result */
        $result = $gateway->createBulkWayBill([
            $orderShipmentData,
        ]);

        $this->assertIsArray($result);
        $responseData = $result[0];
        $this->assertInstanceOf(CreateWayBillResponseData::class, $responseData);
    }

    public function testCreateBulkWayBillFails(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();

        $cityMockResponse = $this->getMockResponseFromFile(InOutFactory::GET_CITIES_JSON_FILE_NAME);
        $awbMockResponse = $this->getMockResponseFromFile(InOutFactory::CREATE_WAY_BILL_JSON_FILE_NAME, 422);

        $httpClientMock = new MockHttpClient([
            $cityMockResponse,
            $awbMockResponse,
        ]);

        $gateway = $this->getGateway($httpClientMock);

        /** @var array $result */
        $result = $gateway->createBulkWayBill([
            $orderShipmentData,
        ]);

        $this->assertArrayHasKey('error', $result[0]);
    }

    public function testMapWayBillFails(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $mockResponse = $this->getMockResponseFromFile(InOutFactory::DOWNLOAD_WAY_BILL_JSON_FILE_NAME, 422);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        $mapWayBillRequestData = new MapWayBillRequestData(
            orderId: WayBillFactory::ORDER_ID,
            shippingNumber: WayBillFactory::SHIPPING_NUMBER,
            shippingInfo: WayBillFactory::SHIPPING_INFO,
        );

        $result = $gateway->mapWayBills([
            $mapWayBillRequestData,
        ]);

        $this->assertInstanceOf(ConstraintViolationList::class, $result);
    }

    public function testDownloadWayBillWorks(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $mockResponse = $this->getMockResponseFromFile(InOutFactory::DOWNLOAD_WAY_BILL_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        $downloadRequestData = ProvidersFactory::getDownloadWayBillRequestData();

        $uri = str_replace('{shippingNumber}', $downloadRequestData->getShippingNumber(), self::DOWNLOAD_WAY_BILL_URI);
        $query = http_build_query(['testMode' => InOutFactory::TEST_MODE_DEFAULT]);

        /** @var DownloadWayBillResponseData $result */
        $result = $gateway->downloadWayBill($downloadRequestData);

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith($uri.'?'.$query, $mockResponse->getRequestUrl());

        $this->assertInstanceOf(DownloadWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::FILE_TYPE, $result->getType());
        $this->assertSame(WayBillFactory::FILE_CONTENT, $result->getContent());
    }

    public function testGetCouriersOfficesWorks(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        $couriersFileName = str_replace(
            '{companyId}',
            (string) InOutFactory::INOUT_TEST_COMPANY_ID,
            InOutFactory::GET_COURIERS_JSON_FILE_NAME
        );

        $expectedCourierData = array_map(function (array $courier) {
            return InOutFactory::getCourierDataFromArray($courier);
        }, $this->getDecodedFileResponse($couriersFileName));

        $mockResponse = $this->getMockResponseFromFile($couriersFileName);
        $httpClientMock = new MockHttpClient($mockResponse);

        $settings = ProvidersFactory::PROVIDER_SETTINGS_INOUT;

        $providerSettings = ProvidersFactory::getProviderSettings(settings: $settings);
        $gateway = $this->getGateway($httpClientMock, $providerSettings);

        /** @var CourierData[] $couriers */
        $couriers = $gateway->getCouriers(InOutFactory::INOUT_TEST_COMPANY_ID);
        $this->assertEquals($expectedCourierData, $couriers);

        foreach ($couriers as $courier) {
            $jsonFile = str_replace('{courierId}', (string) $courier->getId(), InOutFactory::GET_COURIERS_OFFICES_JSON_FILE_NAME);

            $mockResponse = $this->getMockResponseFromFile($jsonFile);

            $expectedCourierOfficeData = array_map(function (array $courier) {
                return InOutFactory::getCourierOfficeDataFromArray($courier);
            }, $this->getDecodedFileResponse($jsonFile));

            $httpClientMock = new MockHttpClient($mockResponse);

            $uri = str_replace('{courierId}', (string) $courier->getId(), self::GET_COURIER_OFFICES_URI);

            $settings = ProvidersFactory::PROVIDER_SETTINGS_INOUT;
            $settings['courierId'] = (string) $courier->getId();

            $providerSettings = ProvidersFactory::getProviderSettings(settings: $settings);
            $gateway = $this->getGateway($httpClientMock, $providerSettings);

            $filters = new InOutCourierOfficeFilters($settings['courierId'], null);
            $result = $gateway->getCourierOffices($filters);

            $this->assertSame('GET', $mockResponse->getRequestMethod());
            $this->assertStringEndsWith($uri, $mockResponse->getRequestUrl());
            $this->assertArrayNotHasKey('body', $mockResponse->getRequestOptions());

            $this->assertEquals($expectedCourierOfficeData, $result);
        }
    }

    public function testGetCouriersOfficesAcceptsInoutCourierOfficeFiltersOnly(): void
    {
        $mockResponse = $this->getMockResponse('');
        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);
        $filters = $this->createMock(CourierOfficeFiltersInterface::class);

        $this->expectException(InvalidArgumentException::class);

        $gateway->getCourierOffices($filters);
    }

    public function testGetShipmentStatus(): void
    {
        $mockResponse = $this->getMockResponseFromFile(InOutFactory::WAY_BILL_STATUS_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock);

        $request = new ShipmentStatusRequestData([1054632024391, 1054632463763, 1060381281], []);

        /** @var ShipmentStatusResponseData $shipmentStatus */
        $shipmentStatus = $gateway->getShipmentStatus($request);
        $expectedResponse = new ShipmentStatusResponseData([
            new ShipmentStatus(
                '1060381281',
                true,
                'Delivered',
            ),
            new ShipmentStatus(
                '1054632024391',
                false,
                'New',
            ),
            new ShipmentStatus(
                '1054632463763',
                false,
                'New',
            ),
        ], $shipmentStatus->getDateTime());

        $this->assertEquals($expectedResponse, $shipmentStatus);
    }

    public function testGetShipmentStatusReturnError(): void
    {
        $mockResponse = new MockResponse('{"error": "Request error"}', [
            'http_code' => 400,
        ]);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock);

        $request = new ShipmentStatusRequestData([1054632024391, 1054632463763, 1060381281], []);

        $shipmentStatus = $gateway->getShipmentStatus($request);

        $this->assertInstanceOf(ConstraintViolation::class, $shipmentStatus);
    }

    /**
     * @dataProvider provideStatusData
     */
    public function testGetStatusMapping(array $status, string $expected): void
    {
        $gateway = $this->getGateway(new MockHttpClient());

        foreach ($status as $providerStatus) {
            $result = $gateway->getStatusMapping($providerStatus);
            $this->assertSame($expected, $result);
        }
    }

    public function provideStatusData(): array
    {
        return [
            'default' => [
                'status' => [9999999],
                'expected' => '',
            ],
            'pending' => [
                'status' => InOutFactory::PENDING_STATUSES,
                'expected' => 'pending',
            ],
            'shipped' => [
                'status' => InOutFactory::SHIPPED_STATUSES,
                'expected' => 'shipped',
            ],
            'delivered' => [
                'status' => InOutFactory::DELIVERY_STATUSES,
                'expected' => 'delivered',
            ],
            'canceled' => [
                'status' => InOutFactory::CANCELLED_STATUSES,
                'expected' => 'canceled',
            ],
        ];
    }

    public function testInOutResponseReturnJsonError(): void
    {
        $tenant = TenantFactory::getTenant(apps: TenantFactory::APPS_CONFIG_ENCRYPTED_THIRD);

        $this->setTenantInRequest($tenant);

        /** @var string $body */
        $body = json_encode(['errors' => 'Invalid token']);
        $mockResponse = $this->getMockResponse($body, 401);
        $httpClientMock = new MockHttpClient($mockResponse);

        $settings = ProvidersFactory::PROVIDER_SETTINGS_INOUT;

        $providerSettings = ProvidersFactory::getProviderSettings(settings: $settings);
        $gateway = $this->getGateway($httpClientMock, $providerSettings);

        /** @var ConstraintViolation $response */
        $response = $gateway->getCompanies();

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::GET_COMPANIES_URI, $mockResponse->getRequestUrl());
        $this->assertArrayNotHasKey('body', $mockResponse->getRequestOptions());

        $this->assertInstanceOf(ConstraintViolation::class, $response);
        $this->assertSame('common', $response->getPath());
        $this->assertSame('Gateway invalid request', $response->getMessage());
    }

    public function testSetInvalidProviderSettingsThrowsTenantShipmentConfigException(): void
    {
        $this->setTenantInRequest();

        $providerSettings = ProvidersFactory::getProviderSettings(settings: []);

        $this->expectException(TenantProvidersException::class);
        $this->expectExceptionMessage('Missing or invalid companyId and/or courierId');

        $this->getGateway(new MockHttpClient(), $providerSettings);
    }

    /**
     * @throws TenantIdException
     * @throws TenantProvidersException
     */
    private function getGateway(HttpClientInterface $httpClient, ?ProviderSettings $providerSettings = null): InOutShippingGateway
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);

        /** @var Request $request */
        $request = $requestStack->getCurrentRequest();

        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get(LoggerInterface::class);

        /** @var ProvidersEncryptorInterface $encryptor */
        $encryptor = self::getContainer()->get(ProvidersEncryptorInterface::class);

        $gateway = new InOutShippingGateway($request, $encryptor, $httpClient, $logger);
        $gateway->setProviderSettings($providerSettings ?? ProvidersFactory::getProviderSettings());

        return $gateway;
    }
}

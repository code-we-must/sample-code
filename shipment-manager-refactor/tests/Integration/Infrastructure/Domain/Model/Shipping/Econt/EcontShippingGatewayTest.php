<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Domain\Model\Shipping\Econt;

use App\Application\Service\Encryption\ProvidersEncryptorInterface;
use App\Application\Service\TranslatorInterface;
use App\Application\Shared\OrdersHelper;
use App\Domain\Model\Error\ConstraintViolation;
use App\Domain\Model\Error\ConstraintViolationList;
use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\Provider\ProviderSettingsCredentials;
use App\Domain\Model\SenderAddress\SenderAddressRepositoryInterface;
use App\Domain\Model\ShipmentStatus\ShipmentStatus;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\CourierOfficeFiltersInterface;
use App\Domain\Model\Shipping\Econt\CashOnDeliveryAgreement;
use App\Domain\Model\Shipping\Econt\EcontCourierOfficeFilters;
use App\Domain\Model\ShippingMethod\PaymentMethod;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Domain\Model\WayBill\MapWayBillRequestData;
use App\Domain\Model\WayBill\MapWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\CourierOfficeData;
use App\Infrastructure\Domain\Model\Shipping\Econt\EcontShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\Econt\RedisEcontShippingOfficesRepository;
use App\Infrastructure\Exception\GatewayErrorException;
use App\Infrastructure\Exception\TenantIdException;
use App\Infrastructure\Persistence\Connection\DoctrineTenantConnection;
use App\Infrastructure\Service\Econt\CreateLabelRequestDataMapperInterface;
use App\Infrastructure\Service\Econt\CreateLabelRequestParamsMapperInterface;
use App\Infrastructure\Service\Econt\EcontRequestErrorHandler;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Shared\Factory\CashOnDeliveryAgreementFactory;
use App\Tests\Shared\Factory\CreateLabelRequestParamsFactory;
use App\Tests\Shared\Factory\EcontCreateLabelRequestDataFactory;
use App\Tests\Shared\Factory\EcontFactory;
use App\Tests\Shared\Factory\OrderFactory;
use App\Tests\Shared\Factory\OrderShipmentDataFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\ShippingAddressFactory;
use App\Tests\Shared\Factory\ShippingPriceEstimationDataFactory;
use App\Tests\Shared\Factory\TenantFactory;
use App\Tests\Shared\Factory\WayBillFactory;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TypeError;

final class EcontShippingGatewayTest extends IntegrationTestCase
{
    private DoctrineTenantConnection $connection;
    private const URI_ESTIMATE_PRICE = 'Shipments/LabelService.createLabel.json';
    private const URI_CREATE_WAY_BILL = 'Shipments/LabelService.createLabel.json';
    private const URI_GET_COURIER_OFFICES = 'Nomenclatures/NomenclaturesService.getOffices.json';

    private const URI_CLIENT_PROFILE = 'Profile/ProfileService.getClientProfiles.json';

    private RedisEcontShippingOfficesRepository $econtShippingOfficesRepository;
    private SenderAddressRepositoryInterface $senderAddressRepository;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = TenantFactory::getTenant();
        $this->setTenantInRequest($tenant);
        $this->connection = $this->createDoctrineTenantConnection();

        /** @var SenderAddressRepositoryInterface $senderAddressRepository */
        $senderAddressRepository = self::getContainer()->get(SenderAddressRepositoryInterface::class);
        $this->senderAddressRepository = $senderAddressRepository;
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

    public function testValidateCredentialsReturnNull(): void
    {
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_PROFILE_JSON_FILE_NAME);

        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        $providerSettingsCredentials = new ProviderSettingsCredentials(
            token: null,
            username: 'test',
            password: 'test',
            testMode: true,
        );

        $response = $gateway->validateCredentials($providerSettingsCredentials);

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_CLIENT_PROFILE, $mockResponse->getRequestUrl());

        $this->assertNull($response);
    }

    public function testCalculatePriceToAddress(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null)
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::PRICE_ESTIMATE_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $expectedJson = [
            'label' => EcontCreateLabelRequestDataFactory::create(
                receiverData: EcontCreateLabelRequestDataFactory::createReceiverData(officeCode: null),
                shipmentNumber: '',
                orderNumber: '',
            ),
            'mode' => 'calculate',
        ];

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_ESTIMATE_PRICE, $mockResponse->getRequestUrl());

        $this->assertEquals($expectedJson, $this->getMockRequestBody($mockResponse));

        $this->assertInstanceOf(ShippingPriceEstimationResponseData::class, $result);
        $this->assertSame(EcontFactory::ESTIMATE_PRICE, $result->getPrice());
    }

    public function testCalculatePriceToOffice(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress()
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::PRICE_ESTIMATE_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $expectedJson = [
            'label' => EcontCreateLabelRequestDataFactory::create(
                receiverData: EcontCreateLabelRequestDataFactory::createReceiverData(),
                shipmentNumber: '',
                orderNumber: '',
            ),
            'mode' => 'calculate',
        ];

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_ESTIMATE_PRICE, $mockResponse->getRequestUrl());

        $this->assertEquals($expectedJson, $this->getMockRequestBody($mockResponse));

        $this->assertInstanceOf(ShippingPriceEstimationResponseData::class, $result);
        $this->assertSame(EcontFactory::ESTIMATE_PRICE, $result->getPrice());
    }

    public function testCalculatePriceSenderAddressNotFound(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::PRICE_ESTIMATE_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'defaultSenderAddressId' => (string) Uuid::v4(),
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        while ($defaultSenderAddress = $this->senderAddressRepository->findDefaultAddress()) {
            $this->senderAddressRepository->remove($defaultSenderAddress);
        }

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertEquals(new ConstraintViolation('Sender address not found'), $result);
    }

    public function testCalculatePriceRequestError(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
        );
        $mockResponse = new MockResponse('{}', [
            'http_code' => 400,
        ]);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'defaultSenderAddressId' => (string) Uuid::v4(),
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertEquals(new ConstraintViolation('Gateway invalid request'), $result);
    }

    public function testCalculateCashOnDeliveryAgreementRequestError(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
        );
        $mockResponse = new MockResponse('{}', [
            'http_code' => 400,
        ]);
        $httpClientMock = new MockHttpClient([$mockResponse]);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'useCashOnDelivery' => true,
                'useCashOnDeliveryAgreement' => true,
                'cashOnDeliveryAgreementNumber' => CashOnDeliveryAgreementFactory::NUMBER,
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertEquals(new ConstraintViolation('Gateway invalid request'), $result);
    }

    public function testCalculateCashOnDeliveryAgreementNotFound(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_PROFILE_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'useCashOnDelivery' => true,
                'useCashOnDeliveryAgreement' => true,
                'cashOnDeliveryAgreementNumber' => CashOnDeliveryAgreementFactory::NUMBER_2,
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertEquals(new ConstraintViolation('COD Agreement not found.'), $result);
    }

    /**
     * @dataProvider dataProviderCashOnDelivery
     */
    public function testCalculatePriceAndCashOnDeliveryUsage(PaymentMethod $paymentMethod, string $jsonFilename, array $settings, bool $isCodEnabled): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            codAmount: PaymentMethod::CashOnDelivery === $paymentMethod ? ShippingPriceEstimationDataFactory::COD_AMOUNT : 0,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null)
        );
        $mockResponse = $this->getMockResponseFromFile($jsonFilename);
        $gateway = $this->getGateway(new MockHttpClient($mockResponse), ProvidersFactory::getProviderSettingsForEcont(
            settings: $settings
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertInstanceOf(ShippingPriceEstimationResponseData::class, $result);
    }

    public function testCalculatePriceCashOnDeliveryAttemptButNotAllowed(): void
    {
        $requestData = ShippingPriceEstimationDataFactory::getData(
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null)
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::PRICE_ESTIMATE_JSON_FILE_NAME);
        $gateway = $this->getGateway(new MockHttpClient($mockResponse), ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT_WITH_COD_FALSE
        ));

        /** @var ShippingPriceEstimationResponseData $result */
        $result = $gateway->calculatePrice($requestData);

        $this->assertEquals(new ConstraintViolation('Cash on delivery is not allowed'), $result);
    }

    public function testCreateBulkWayBillWorks(): void
    {
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::CREATE_WAY_BILL_BULK_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 11199,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );

        $result = $gateway->createBulkWayBill([
            $orderShipmentData,
        ]);

        $this->assertIsArray($result);
    }

    public function testCreateBulkWayBillFails(): void
    {
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::CREATE_WAY_BILL_BULK_JSON_FILE_NAME, 422);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 11199,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );

        $result = $gateway->createBulkWayBill([
            $orderShipmentData,
        ]);

        $this->assertInstanceOf(ConstraintViolation::class, $result);
    }

    public function testMapWayBillsSuccess(): void
    {
        $httpClientMock = new MockHttpClient([]);

        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        $mapWayBilLRequestData = new MapWayBillRequestData(
            orderId: WayBillFactory::ORDER_ID,
            shippingNumber: WayBillFactory::SHIPPING_NUMBER,
            shippingInfo: [
                'pdfURL' => '/',
                'reference' => OrderFactory::REFERENCE,
            ],
        );

        /** @var MapWayBillResponseData[] $result */
        $result = $gateway->mapWayBills([
            $mapWayBilLRequestData,
        ]);

        $responseData = $result[0];

        $this->assertIsArray($result);
        $this->assertInstanceOf(MapWayBillResponseData::class, $responseData);
    }

    public function testMapWayBillsFails(): void
    {
        $httpClientMock = new MockHttpClient([]);

        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        $mapWayBilLRequestData = new MapWayBillRequestData(
            orderId: WayBillFactory::ORDER_ID,
            shippingNumber: WayBillFactory::SHIPPING_NUMBER,
            shippingInfo: null,
        );

        /** @var ConstraintViolationList $result */
        $result = $gateway->mapWayBills([
            $mapWayBilLRequestData,
        ]);

        $this->assertInstanceOf(ConstraintViolationList::class, $result);
    }

    public function testMapOrderId(): void
    {
        $httpClientMock = new MockHttpClient([]);

        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 11199,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );

        $createWayBillResponseData = new CreateWayBillResponseData(
            shippingNumber: CreateLabelRequestParamsFactory::CUSTOM_SHIPPING_NUMBER,
            shippingInfo: null,
            shippingTrackingUrl: null
        );

        $result = $gateway->mapOrderId([
            $orderShipmentData,
        ], $createWayBillResponseData);

        $this->assertSame($orderShipmentData->getOrderId(), $result);
    }

    public function testCreateWayBillToAddress(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 11199,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::CREATE_WAY_BILL_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $expectedJson = [
            'label' => EcontCreateLabelRequestDataFactory::create(
                description: 'Order Description',
                receiverData: EcontCreateLabelRequestDataFactory::createReceiverData(officeCode: null),
                services: EcontCreateLabelRequestDataFactory::createServices(declaredValueAmount: 109.99),
                products: [
                    [
                        'quantity' => 1,
                        'weight' => 0.1,
                        'price' => 1.0,
                    ],
                ],
            ),
            'mode' => 'create',
        ];

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_CREATE_WAY_BILL, $mockResponse->getRequestUrl());
        $this->assertEquals($expectedJson, $this->getMockRequestBody($mockResponse));

        $this->assertInstanceOf(CreateWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::ECONT_SHIPPING_NUMBER_FROM_RESPONSE, $result->getShippingNumber());
    }

    public function testCreateWayBillToOffice(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 11199,
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::CREATE_WAY_BILL_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $expectedJson = [
            'label' => EcontCreateLabelRequestDataFactory::create(
                description: 'Order Description',
                services: EcontCreateLabelRequestDataFactory::createServices(declaredValueAmount: 109.99),
                products: [
                    [
                        'quantity' => 1,
                        'weight' => 0.1,
                        'price' => 1.0,
                    ],
                ],
            ),
            'mode' => 'create',
        ];

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_CREATE_WAY_BILL, $mockResponse->getRequestUrl());
        $this->assertEquals($expectedJson, $this->getMockRequestBody($mockResponse));

        $this->assertInstanceOf(CreateWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::ECONT_SHIPPING_NUMBER_FROM_RESPONSE, $result->getShippingNumber());
    }

    /**
     * @dataProvider dataProviderCashOnDelivery
     */
    public function testCreateWayBillAndCashOnDeliveryUsage(PaymentMethod $paymentMethod, string $jsonFilename, array $settings, bool $isCodEnabled): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: $paymentMethod->value,
            totalPrice: 11199,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );
        $mockResponse = $this->getMockResponseFromFile($jsonFilename);

        $gateway = $this->getGateway(new MockHttpClient($mockResponse), ProvidersFactory::getProviderSettingsForEcont(
            settings: $settings
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);
        $shippingInfo = $result->getShippingInfo();

        $this->assertInstanceOf(CreateWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::ECONT_SHIPPING_NUMBER_FROM_RESPONSE, $result->getShippingNumber());

        $this->assertEquals($isCodEnabled, in_array('CD', array_column($shippingInfo['services'] ?? [], 'type')));
    }

    public function testCreateWayBillCashOnDeliveryAttemptButNotAllowed(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData(
            currency: 'BGN',
            paymentMethod: PaymentMethod::CashOnDelivery->value,
            totalPrice: 11199,
            shippingAddress: ShippingAddressFactory::getShippingAddress(officeId: null),
            shippingPrice: 200,
            shippingPriceWithoutFees: 100,
        );
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::CREATE_WAY_BILL_JSON_FILE_NAME);

        $gateway = $this->getGateway(new MockHttpClient($mockResponse), ProvidersFactory::getProviderSettingsForEcont(
            settings: ProvidersFactory::PROVIDER_SETTINGS_ECONT_WITH_COD_FALSE
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertEquals(new ConstraintViolation('Cash on delivery is not allowed'), $result);
    }

    public function testCreateWayBillSenderAddressNotFound(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::CREATE_WAY_BILL_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'defaultSenderAddressId' => (string) Uuid::v4(),
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        while ($defaultSenderAddress = $this->senderAddressRepository->findDefaultAddress()) {
            $this->senderAddressRepository->remove($defaultSenderAddress);
        }

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertEquals(new ConstraintViolation('Sender address not found'), $result);
    }

    public function testCreateWayBillCashOnDeliveryAgreementRequestError(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();
        $mockResponse = new MockResponse('{}', [
            'http_code' => 400,
        ]);
        $httpClientMock = new MockHttpClient([$mockResponse]);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'useCashOnDelivery' => true,
                'useCashOnDeliveryAgreement' => true,
                'cashOnDeliveryAgreementNumber' => CashOnDeliveryAgreementFactory::NUMBER,
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertEquals(new ConstraintViolation('Gateway invalid request'), $result);
    }

    public function testCreateWayBillCashOnDeliveryAgreementNotFound(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_PROFILE_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'useCashOnDelivery' => true,
                'useCashOnDeliveryAgreement' => true,
                'cashOnDeliveryAgreementNumber' => CashOnDeliveryAgreementFactory::NUMBER_2,
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertEquals(new ConstraintViolation('COD Agreement not found.'), $result);
    }

    public function testCreateWayBillRequestError(): void
    {
        $orderShipmentData = OrderShipmentDataFactory::getCacheData();
        $mockResponse = new MockResponse('{}', [
            'http_code' => 400,
        ]);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock, ProvidersFactory::getProviderSettingsForEcont(
            settings: [
                'defaultSenderAddressId' => (string) Uuid::v4(),
            ] + ProvidersFactory::PROVIDER_SETTINGS_ECONT
        ));

        /** @var CreateWayBillResponseData $result */
        $result = $gateway->createWayBill($orderShipmentData);

        $this->assertEquals(new ConstraintViolation('Gateway invalid request'), $result);
    }

    public function testDownloadWayBillWorks(): void
    {
        $mockResponse = $this->getMockResponse('file-content-data', 200, [
            'http_code' => 200,
            'response_headers' => ['Content-Type: application/pdf'],
        ]);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock);
        $downloadRequestData = ProvidersFactory::getDownloadWayBillRequestData(
            wayBillInfo: ['pdfURL' => '/']
        );

        /** @var DownloadWayBillResponseData $result */
        $result = $gateway->downloadWayBill($downloadRequestData);

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith('/', $mockResponse->getRequestUrl());

        $this->assertInstanceOf(DownloadWayBillResponseData::class, $result);
        $this->assertSame(WayBillFactory::FILE_TYPE, $result->getType());
        $this->assertSame(WayBillFactory::FILE_CONTENT, $result->getContent());
    }

    public function testEcontGetCouriersOfficesWorks(): void
    {
        $expectedOfficeJSON = $this->getDecodedFileResponse(EcontFactory::GET_FILTERED_OFFICES_JSON_FILE_NAME);
        $expectedOfficeData = array_map(function (array $office) {
            return EcontFactory::getOfficeDataFromArray($office);
        }, $expectedOfficeJSON['offices']);

        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_OFFICES_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);

        $providerSettings = ProvidersFactory::getProviderSettingsForEcont();

        $gateway = $this->getGateway($httpClientMock, $providerSettings);

        $this->econtShippingOfficesRepository->remove($providerSettings->getCountry());

        $filters = new EcontCourierOfficeFilters($providerSettings->getCountry(), null);
        /** @var CourierOfficeData[] $offices */
        $offices = $gateway->getCourierOffices($filters);
        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_GET_COURIER_OFFICES, $mockResponse->getRequestUrl());
        $this->assertArrayHasKey('body', $mockResponse->getRequestOptions());
        $this->assertEquals($expectedOfficeData, $offices);
    }

    public function testEcontResponseReturnJsonError(): void
    {
        /** @var string $body */
        $body = json_encode(['errors' => 'Invalid username/password']);
        $mockResponse = $this->getMockResponse($body, 401);

        $httpClientMock = new MockHttpClient($mockResponse);

        $providerSettings = ProvidersFactory::getProviderSettingsForEcont();

        $gateway = $this->getGateway($httpClientMock, $providerSettings);
        $this->econtShippingOfficesRepository->remove($providerSettings->getCountry());

        $filters = new EcontCourierOfficeFilters($providerSettings->getCountry(), null);
        /** @var ConstraintViolation $response */
        $response = $gateway->getCourierOffices($filters);

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_GET_COURIER_OFFICES, $mockResponse->getRequestUrl());

        $this->assertInstanceOf(ConstraintViolation::class, $response);
        $this->assertSame('common', $response->getPath());
        $this->assertSame('Gateway invalid request', $response->getMessage());
    }

    public function testEcontGetCouriersOfficesWorksWithCache(): void
    {
        $expectedOfficeJSON = $this->getDecodedFileResponse(EcontFactory::GET_FILTERED_OFFICES_JSON_FILE_NAME);
        $expectedOfficeData = array_map(function (array $office) {
            return EcontFactory::getOfficeDataFromArray($office);
        }, $expectedOfficeJSON['offices']);

        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_OFFICES_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        $providerSettings = ProvidersFactory::getProviderSettingsForEcont();
        $filters = new EcontCourierOfficeFilters($providerSettings->getCountry(), null);

        /** @var CourierOfficeData[] $offices */
        $offices = $gateway->getCourierOffices($filters);

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_GET_COURIER_OFFICES, $mockResponse->getRequestUrl());
        $this->assertArrayHasKey('body', $mockResponse->getRequestOptions());
        $this->assertEquals($expectedOfficeData, $offices);

        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_OFFICES_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock, $providerSettings);
        $gateway->getCourierOffices($filters);
        $this->econtShippingOfficesRepository->remove($providerSettings->getCountry());

        // Get offices from cache no request is made and request method is null and throw TypeError
        $this->expectException(TypeError::class);
        $mockResponse->getRequestMethod();
    }

    public function testGetCouriersOfficesAcceptsEcontCourierOfficeFiltersOnly(): void
    {
        $mockResponse = $this->getMockResponse('');
        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);
        $filters = $this->createMock(CourierOfficeFiltersInterface::class);

        $this->expectException(InvalidArgumentException::class);

        $gateway->getCourierOffices($filters);
    }

    public function testGetCashOnDeliveryAgreements(): void
    {
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_PROFILE_JSON_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);

        $expectedAgreements = [
            new CashOnDeliveryAgreement('CD38715', 'test'),
            new CashOnDeliveryAgreement('CD38930', 'test'),
            new CashOnDeliveryAgreement('CD38932', 'тест офис'),
            new CashOnDeliveryAgreement('CD39842', 'ЕТ КОНСУЛТ - ИВАН КИРЯЗОВ'),
            new CashOnDeliveryAgreement('CD39855', 'ЕТ КОНСУЛТ - ИВАН КИРЯЗОВ'),
            new CashOnDeliveryAgreement('CD39978', 'ЕТ КОНСУЛТ - ИВАН КИРЯЗОВ'),
            new CashOnDeliveryAgreement('CD40165', 'ЕТ КОНСУЛТ - ИВАН КИРЯЗОВ'),
            new CashOnDeliveryAgreement('CD40188', 'ЕТ КОНСУЛТ - ИВАН КИРЯЗОВ'),
            new CashOnDeliveryAgreement('CD40236', 'ЕТ КОНСУЛТ - ИВАН КИРЯЗОВ'),
        ];

        $gateway = $this->getGateway($httpClientMock);
        /** @var CashOnDeliveryAgreement[] $agreements */
        $agreements = iterator_to_array($gateway->getCashOnDeliveryAgreements());

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_CLIENT_PROFILE, $mockResponse->getRequestUrl());

        $this->assertEqualsCanonicalizing($expectedAgreements, $agreements);
    }

    public function testGetCashOnDeliveryAgreementsInvalidCredentials(): void
    {
        /** @var string $body */
        $body = json_encode(['errors' => 'Invalid username/password']);
        $mockResponse = $this->getMockResponse($body, 401);
        $httpClientMock = new MockHttpClient($mockResponse);

        $gateway = $this->getGateway($httpClientMock);

        $this->expectException(GatewayErrorException::class);

        $response = $gateway->getCashOnDeliveryAgreements();
        iterator_to_array($response);

        $this->assertSame('GET', $mockResponse->getRequestMethod());
        $this->assertStringEndsWith(self::URI_CLIENT_PROFILE, $mockResponse->getRequestUrl());
    }

    public function testGetShipmentStatus(): void
    {
        $mockResponse = $this->getMockResponseFromFile(EcontFactory::GET_SHIPMENT_STATUS_FILE_NAME);
        $httpClientMock = new MockHttpClient($mockResponse);
        $gateway = $this->getGateway($httpClientMock);

        $request = new ShipmentStatusRequestData([1051603611987, 1051603611994], [
            1051603611994 => [
                'price' => [
                    'details' => [
                        'codPremium' => [
                            'amount' => 1.5,
                        ],
                    ],
                ],
            ],
        ]);

        /** @var ShipmentStatusResponseData $shipmentStatus */
        $shipmentStatus = $gateway->getShipmentStatus($request);
        $expectedResponse = new ShipmentStatusResponseData([
            new ShipmentStatus(
                '1051603611987',
                false,
                'pending',
            ),
            new ShipmentStatus(
                '1051603611994',
                true,
                'delivered',
            ),
        ], $shipmentStatus->getDateTime());

        $this->assertEquals($expectedResponse, $shipmentStatus);
    }

    public function dataProviderCashOnDelivery(): array
    {
        return [
            'CodDisabledInSettingsAndOnCheckout' => [
                'paymentMethod' => PaymentMethod::Braintree,
                'jsonFilename' => EcontFactory::CREATE_WAY_BILL_JSON_FILE_NAME,
                'settings' => ProvidersFactory::PROVIDER_SETTINGS_ECONT_WITH_COD_FALSE,
                'isCodEnabled' => false,
            ],
            'CodEnabledInSettingsAndDisabledOnCheckout' => [
                'paymentMethod' => PaymentMethod::Braintree,
                'jsonFilename' => EcontFactory::CREATE_WAY_BILL_JSON_FILE_NAME,
                'settings' => ProvidersFactory::PROVIDER_SETTINGS_ECONT,
                'isCodEnabled' => false,
            ],
            'CodEnabledInSettingsAndOnCheckout' => [
                'paymentMethod' => PaymentMethod::CashOnDelivery,
                'jsonFilename' => EcontFactory::CREATE_WAY_BILL_WITH_COD_JSON_FILE_NAME,
                'settings' => ProvidersFactory::PROVIDER_SETTINGS_ECONT,
                'isCodEnabled' => true,
            ],
        ];
    }

    /**
     * @throws TenantIdException
     */
    private function getGateway(HttpClientInterface $httpClient, ?ProviderSettings $providerSettings = null): EcontShippingGateway
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);

        /** @var Request $request */
        $request = $requestStack->getCurrentRequest();

        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get(LoggerInterface::class);

        /** @var ProvidersEncryptorInterface $encryptor */
        $encryptor = self::getContainer()->get(ProvidersEncryptorInterface::class);

        /** @var SenderAddressRepositoryInterface $senderAddressRepository */
        $senderAddressRepository = self::getContainer()->get(SenderAddressRepositoryInterface::class);

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        /** @var RedisEcontShippingOfficesRepository $econtShippingOfficesRepository */
        $econtShippingOfficesRepository = self::getContainer()->get(RedisEcontShippingOfficesRepository::class);
        $this->econtShippingOfficesRepository = $econtShippingOfficesRepository;

        /** @var CreateLabelRequestDataMapperInterface $createLabelRequestDataMapper */
        $createLabelRequestDataMapper = self::getContainer()->get(CreateLabelRequestDataMapperInterface::class);

        /** @var CreateLabelRequestParamsMapperInterface $createLabelRequestParamsMapper */
        $createLabelRequestParamsMapper = self::getContainer()->get(CreateLabelRequestParamsMapperInterface::class);

        /** @var EcontRequestErrorHandler $requestErrorHandler */
        $requestErrorHandler = self::getContainer()->get(EcontRequestErrorHandler::class);

        /** @var OrdersHelper $ordersHelper */
        $ordersHelper = self::getContainer()->get(OrdersHelper::class);

        $gateway = new EcontShippingGateway(
            $request,
            $encryptor,
            $httpClient,
            $logger,
            $senderAddressRepository,
            $translator,
            $econtShippingOfficesRepository,
            $createLabelRequestParamsMapper,
            $createLabelRequestDataMapper,
            $requestErrorHandler,
            $ordersHelper,
        );
        $gateway->setProviderSettings($providerSettings ?? ProvidersFactory::getProviderSettingsForEcont());

        return $gateway;
    }
}

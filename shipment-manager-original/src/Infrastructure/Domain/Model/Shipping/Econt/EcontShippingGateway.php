<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\Econt;

use App\Application\Exception\CashOnDeliveryAgreementNotFoundException;
use App\Application\Exception\CashOnDeliveryNotAllowedException;
use App\Application\Exception\SenderAddressNotFoundException;
use App\Application\Service\Encryption\ProvidersEncryptorInterface;
use App\Application\Service\TranslatorInterface;
use App\Application\Shared\OrdersHelper;
use App\Domain\Model\Error\ConstraintViolation;
use App\Domain\Model\Error\ConstraintViolationList;
use App\Domain\Model\Order\OrderShippingData;
use App\Domain\Model\Provider\ProviderSettingsCredentials;
use App\Domain\Model\SenderAddress\SenderAddress;
use App\Domain\Model\SenderAddress\SenderAddressRepositoryInterface;
use App\Domain\Model\ShipmentStatus\ShipmentStatus;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\CashOnDeliveryPolicy;
use App\Domain\Model\Shipping\CourierOfficeFiltersInterface;
use App\Domain\Model\Shipping\Econt\CashOnDeliveryAgreement;
use App\Domain\Model\Shipping\Econt\EcontCourierOfficeFilters;
use App\Domain\Model\Shipping\Econt\EcontShippingGatewayInterface;
use App\Domain\Model\Shipping\Econt\EcontShippingOfficesRepositoryInterface;
use App\Domain\Model\ShippingMethod\PaymentMethod;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationRequestData;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Domain\Model\WayBill\DownloadWayBillRequestData;
use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Domain\Model\WayBill\MapWayBillRequestData;
use App\Domain\Model\WayBill\MapWayBillResponseData;
use App\Domain\Model\WayBill\WaybillStatus;
use App\Infrastructure\Domain\Model\Shipping\AbstractShippingGateway;
use App\Infrastructure\Domain\Model\Shipping\CourierOfficeData;
use App\Infrastructure\Exception\GatewayErrorException;
use App\Infrastructure\Service\Econt\CreateLabelRequestDataMapperInterface;
use App\Infrastructure\Service\Econt\CreateLabelRequestParamsMapperInterface;
use App\Infrastructure\Service\Econt\EcontRequestErrorHandler;
use Exception;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

use function Symfony\Component\String\u;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EcontShippingGateway extends AbstractShippingGateway implements EcontShippingGatewayInterface
{
    private SenderAddressRepositoryInterface $senderAddressRepository;
    private TranslatorInterface $translator;
    private EcontShippingOfficesRepositoryInterface $econtShippingOfficesRepository;
    private CreateLabelRequestDataMapperInterface $createLabelRequestDataMapper;
    private CreateLabelRequestParamsMapperInterface $createLabelRequestParamsMapper;
    private EcontRequestErrorHandler $requestErrorHandler;
    private OrdersHelper $ordersHelper;

    public function __construct(
        Request $request,
        ProvidersEncryptorInterface $encryptor,
        HttpClientInterface $client,
        LoggerInterface $logger,
        SenderAddressRepositoryInterface $senderAddressRepository,
        TranslatorInterface $translator,
        EcontShippingOfficesRepositoryInterface $econtShippingOfficesRepository,
        CreateLabelRequestParamsMapperInterface $createLabelRequestParamsMapper,
        CreateLabelRequestDataMapperInterface $createLabelRequestDataMapper,
        EcontRequestErrorHandler $requestErrorHandler,
        OrdersHelper $ordersHelper,
    ) {
        $this->senderAddressRepository = $senderAddressRepository;
        $this->translator = $translator;
        $this->econtShippingOfficesRepository = $econtShippingOfficesRepository;
        $this->createLabelRequestParamsMapper = $createLabelRequestParamsMapper;
        $this->createLabelRequestDataMapper = $createLabelRequestDataMapper;
        $this->requestErrorHandler = $requestErrorHandler;
        $this->ordersHelper = $ordersHelper;

        parent::__construct($request, $encryptor, $client, $logger);
    }

    private const API_URL = 'https://ee.econt.com/services/';
    private const API_TEST_URL = 'https://demo.econt.com/ee/services/';

    private const URI_ESTIMATE_PRICE = 'Shipments/LabelService.createLabel.json';
    private const URI_CREATE_WAY_BILL = 'Shipments/LabelService.createLabel.json';
    private const URI_GET_COURIER_OFFICES = 'Nomenclatures/NomenclaturesService.getOffices.json';
    private const URI_CLIENT_PROFILE = 'Profile/ProfileService.getClientProfiles.json';
    private const URI_SHIPMENT_STATUS = 'Shipments/ShipmentService.getShipmentStatuses.json';
    private const URI_CREATE_WAY_BILLS = 'Shipments/LabelService.createLabels.json';

    private const MODE_CALCULATE = 'calculate';
    private const MODE_CREATE = 'create';
    private const DOWNLOAD_FILE_TYPE = 'pdf';
    public const PROVIDER_NAME = 'econt';

    public function validateCredentials(ProviderSettingsCredentials $credentials): ?ConstraintViolation
    {
        $this->config = ['username' => $credentials->getUsername(), 'password' => $credentials->getPassword(), 'testMode' => $credentials->isTestMode()];

        $response = $this->request(Request::METHOD_GET, self::URI_CLIENT_PROFILE, [
            'auth_basic' => [$credentials->getUsername(), $credentials->getPassword()],
        ]);

        if ($response instanceof ConstraintViolation) {
            return new ConstraintViolation($this->translator->trans('The username or password you provided is incorrect'));
        }

        $this->addSenderAddresses($response);

        return null;
    }

    public function calculatePrice(ShippingPriceEstimationRequestData $data): ShippingPriceEstimationResponseData|ConstraintViolation
    {
        $providerSettings = $this->providerSettings;

        try {
            if ($this->isCashOnDeliveryAttemptedButNotAllowed($data->getCodAmount() > 0.0)) {
                throw new CashOnDeliveryNotAllowedException();
            }

            $params = $this->createLabelRequestParamsMapper->fromEstimationRequestData($providerSettings, $data);
            $requestData = $this->createLabelRequestDataMapper->fromParams(
                $params,
                $this->getCashOnDeliveryAgreements()
            );
        } catch (SenderAddressNotFoundException|GatewayErrorException|CashOnDeliveryAgreementNotFoundException|CashOnDeliveryNotAllowedException $e) {
            return new ConstraintViolation($e->getMessage());
        }

        /** @var array{label: array{totalPrice: float}}|ConstraintViolation $response */
        $response = $this->request(Request::METHOD_POST, self::URI_ESTIMATE_PRICE, [
            'json' => [
                'label' => $requestData,
                'mode' => self::MODE_CALCULATE,
            ],
        ]);

        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        return new ShippingPriceEstimationResponseData((float) $response['label']['totalPrice']);
    }

    /**
     * @return ConstraintViolation|CourierOfficeData[]
     */
    public function getCourierOffices(CourierOfficeFiltersInterface $filters): array|ConstraintViolation
    {
        if (!$filters instanceof EcontCourierOfficeFilters) {
            throw new InvalidArgumentException(sprintf('Unexpected filters class type. Expected "%s", got "%s".', EcontCourierOfficeFilters::class, get_class($filters)));
        }

        $countryCode = $filters->getCountryCode();
        $address = $filters->getAddress();
        $offices = $this->econtShippingOfficesRepository->find($countryCode, $address);

        if (is_null($offices)) {
            /** @var array{offices: mixed}|ConstraintViolation $response */
            $response = $this->request(Request::METHOD_POST, self::URI_GET_COURIER_OFFICES, [
                'json' => [
                    'countryCode' => $countryCode,
                ],
            ]);
            if ($response instanceof ConstraintViolation) {
                return $response;
            }

            $offices = [];
            foreach ($response['offices'] ?? [] as $officeData) {
                /*
                 * We discard those typegetOfficeDataFromArray of offices, because there is some limitations and dependencies
                 * We will use them, but after we have clear business logic
                 * TODO Research when we can use them and apply the logic depend of store settings and user choices
                 */
                if (
                    isset($officeData['isMPS']) && true === $officeData['isMPS'] || // мобилна пощенска станция
                    isset($officeData['isAPS']) && true === $officeData['isAPS'] || // Еконтомат
                    isset($officeData['isDrive']) && true === $officeData['isDrive'] // Econt Drive (за сега само във Видин).
                ) {
                    continue;
                }

                $offices[] = $this->getOfficeDataFromArray($officeData);
            }

            $this->econtShippingOfficesRepository->save($countryCode, $offices);

            /** @var CourierOfficeData[] $offices */
            $offices = $this->econtShippingOfficesRepository->find($countryCode, $address);
        }

        return $offices;
    }

    public function createWayBill(OrderShippingData $orderShippingData): CreateWayBillResponseData|ConstraintViolation
    {
        $providerSettings = $this->providerSettings;

        try {
            if ($this->isCashOnDeliveryAttemptedButNotAllowed(PaymentMethod::CashOnDelivery->value === $orderShippingData->getPaymentMethod())) {
                throw new CashOnDeliveryNotAllowedException();
            }

            $params = $this->createLabelRequestParamsMapper->fromOrderShippingData($providerSettings, $orderShippingData);
            $requestData = $this->createLabelRequestDataMapper->fromParams(
                $params,
                $this->getCashOnDeliveryAgreements()
            );
        } catch (SenderAddressNotFoundException|GatewayErrorException|CashOnDeliveryAgreementNotFoundException|CashOnDeliveryNotAllowedException $e) {
            return new ConstraintViolation($e->getMessage());
        }

        /** @var array{label: array{shipmentNumber: string}}|ConstraintViolation $response */
        $response = $this->request(Request::METHOD_POST, self::URI_CREATE_WAY_BILL, [
            'json' => [
                'label' => $requestData,
                'mode' => self::MODE_CREATE,
            ],
        ]);

        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        $response['label']['referenece'] = $orderShippingData->getReference();

        return new CreateWayBillResponseData($response['label']['shipmentNumber'], $response['label']);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function downloadWayBill(DownloadWayBillRequestData $downloadWayBillRequestData): DownloadWayBillResponseData|ConstraintViolation
    {
        $fileType = self::DOWNLOAD_FILE_TYPE;

        /** @var array{pdfURL: string} $shipmentInfo */
        $shipmentInfo = $downloadWayBillRequestData->getShippingInfo();

        $fileContent = $this->getGateway()->request(Request::METHOD_GET, $shipmentInfo['pdfURL'])->getContent(false);

        return new DownloadWayBillResponseData($fileType, $fileContent);
    }

    /**
     * @param MapWayBillRequestData[] $mapWayBillRequestDatas
     *
     * @return MapWayBillResponseData[]|ConstraintViolationList
     */
    public function mapWayBills(array $mapWayBillRequestDatas): array|ConstraintViolationList
    {
        $mappedWayBillResponse = [];
        $violations = [];
        foreach ($mapWayBillRequestDatas as $mapWayBillRequestData) {
            $shippingInfo = $mapWayBillRequestData->getShippingInfo();

            if (empty($shippingInfo)) {
                $violations[] = new ConstraintViolation(sprintf('Order %s doesn\'t have shipping information', $mapWayBillRequestData->getOrderId()));
            }

            $pdfUrl = $shippingInfo['pdfURL'] ?? '';

            $mappedWayBillResponse[] = new MapWayBillResponseData(
                orderId: $mapWayBillRequestData->getOrderId(),
                reference: $shippingInfo['reference'] ?? '',
                shippingNumber: $mapWayBillRequestData->getShippingNumber(),
                provider: $this->getProviderName(),
                fileType: $this->defaultFileType,
                url: $pdfUrl,
            );
        }

        if (!empty($violations)) {
            return new ConstraintViolationList($violations);
        }

        return $mappedWayBillResponse;
    }

    /**
     * @param OrderShippingData[] $orderShippingDatas
     *
     * @return CreateWayBillResponseData[]|ConstraintViolation
     */
    public function createBulkWayBill(array $orderShippingDatas): array|ConstraintViolation
    {
        $providerSettings = $this->providerSettings;

        $requestData = [];
        foreach ($orderShippingDatas as $orderShippingData) {
            $params = $this->createLabelRequestParamsMapper->fromOrderShippingData($providerSettings, $orderShippingData);
            $requestData[] = $this->createLabelRequestDataMapper->fromParams(
                $params,
                $this->getCashOnDeliveryAgreements()
            );
        }

        $response = $this->request(Request::METHOD_POST, self::URI_CREATE_WAY_BILLS, [
            'json' => [
                'labels' => $requestData,
                'mode' => self::MODE_CREATE,
            ],
        ]);

        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        $wayBillResponse = [];
        foreach ($response['results'] as $result) {
            $reference = '';
            foreach ($orderShippingDatas as $orderShippingData) {
                $shippingNumber = $this->ordersHelper->generateShippingNumberFromOrderId($orderShippingData->getOrderId());
                if ($shippingNumber === $result['label']['shipmentNumber']) {
                    $reference = $orderShippingData->getReference();
                    break;
                }
            }

            $result['label']['reference'] = $reference;
            $wayBillResponse[] = new CreateWayBillResponseData(
                shippingNumber: $result['label']['shipmentNumber'],
                shippingInfo: $result['label'],
            );
        }

        return $wayBillResponse;
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
            $shippingNumber = $this->ordersHelper->generateShippingNumberFromOrderId($orderShippingData->getOrderId());

            if ($shippingNumber === $wayBillResponse->getShippingNumber()) {
                $orderId = $orderShippingData->getOrderId();
                break;
            }
        }

        return $orderId;
    }

    /**
     * @return Generator<CashOnDeliveryAgreement>
     *
     * @throws GatewayErrorException
     */
    public function getCashOnDeliveryAgreements(): Generator
    {
        $response = $this->request(Request::METHOD_GET, self::URI_CLIENT_PROFILE);

        if ($response instanceof ConstraintViolation) {
            throw new GatewayErrorException($response->getMessage());
        }

        foreach ($response['profiles'] as $profile) {
            $agreements = $profile['cdPayOptions'] ?? [];

            foreach ($agreements as $agreement) {
                yield new CashOnDeliveryAgreement($agreement['num'], $agreement['client']['name']);
            }
        }
    }

    public function getShipmentStatus(ShipmentStatusRequestData $shipmentStatusRequestData): ShipmentStatusResponseData|ConstraintViolation
    {
        $waybillInfos = $shipmentStatusRequestData->getShippingInfo();

        $response = $this->request(Request::METHOD_POST, self::URI_SHIPMENT_STATUS, [
            'json' => [
                'shipmentNumbers' => $shipmentStatusRequestData->getShippingNumbers(),
            ],
        ]);

        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        $result = [];

        foreach ($response['shipmentStatuses'] as $shipmentStatus) {
            $isPaid = false;
            if ($shipmentStatus['status']['cdPaidTime']) {
                $codPrice = $waybillInfos[$shipmentStatus['status']['shipmentNumber']]['price']['details']['codPremium']['amount'] ?? 0;
                if ($codPrice > 0) {
                    $isPaid = true;
                }
            }

            if ($shipmentStatus['status']['deliveryTime']) {
                $status = WaybillStatus::DELIVERED->value;
            } elseif ($shipmentStatus['status']['sendTime']) {
                $status = WaybillStatus::SHIPPED->value;
            } else {
                $status = WaybillStatus::PENDING->value;
            }

            $result[] = new ShipmentStatus(
                intval($shipmentStatus['status']['shipmentNumber']),
                $isPaid,
                $status,
            );
        }

        return new ShipmentStatusResponseData($result, new \DateTime());
    }

    public function getCashOnDeliveryPolicy(): CashOnDeliveryPolicy
    {
        $settings = $this->providerSettings->getSettings();

        return $settings['cashOnDelivery'] ?? false ?
               CashOnDeliveryPolicy::Allowed :
               CashOnDeliveryPolicy::NotAllowed;
    }

    protected function handleRequestException(Exception|ExceptionInterface $ex): ConstraintViolation
    {
        return $this->requestErrorHandler->handle($ex);
    }

    protected function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    protected function getClientOptions(): array
    {
        $username = $this->getConfigVar('username') ?? null;
        $password = $this->getConfigVar('password') ?? null;
        $testMode = $this->getConfigVar('testMode') ?? true;

        $baseUri = $testMode ? self::API_TEST_URL : self::API_URL;

        return [
            'base_uri' => $baseUri,
            'auth_basic' => [$username, $password],
            'http_version' => CURL_HTTP_VERSION_1_0,
        ];
    }

    private function getOfficeDataFromArray(array $office): CourierOfficeData
    {
        $address = $office['address'];
        $city = $address['city'];

        return new CourierOfficeData(
            officeId: $office['code'],
            officeName: $office['name'],
            officeCode: $office['code'],
            area: $city['regionName'],
            cityId: $city['id'],
            cityName: $city['name'],
            cityCode: $city['postCode'],
            address: $address['fullAddress'],
            addressEn: $address['fullAddressEn'] ?? u($address['fullAddress'])->ascii()->toString(),
            cityEn: $city['nameEn']
        );
    }

    private function addSenderAddresses(array $response): void
    {
        if (0 === $this->senderAddressRepository->getCount()) {
            $isDefault = true;
            $profile = current($response['profiles']);

            foreach ($profile['addresses'] ?? [] as $address) {
                $addressShortName = (string) $address['city']['name'].' '.(string) $address['street'];
                $senderAddress = new SenderAddress(
                    id: $this->senderAddressRepository->findNextId(),
                    shortName: trim($addressShortName),
                    senderName: $profile['client']['name'] ?? '',
                    senderPhone: $profile['client']['phones'][0] ?? '',
                    countryCode: $address['city']['country']['code2'],
                    city: $address['city']['regionName'],
                    placeId: 'econt-address',
                    postCode: $address['city']['postCode'],
                    streetName: $address['street'],
                    streetNumber: $address['num'],
                    isDefault: $isDefault,
                );
                $isDefault = false;

                $this->senderAddressRepository->save($senderAddress);
            }
        }
    }
}

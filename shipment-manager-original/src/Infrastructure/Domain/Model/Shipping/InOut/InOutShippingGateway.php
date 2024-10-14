<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\InOut;

use App\Application\Shared\NumberHelper;
use App\Domain\Model\Error\ConstraintViolation;
use App\Domain\Model\Error\ConstraintViolationList;
use App\Domain\Model\Order\OrderShippingData;
use App\Domain\Model\Provider\ProviderSettings;
use App\Domain\Model\Provider\ProviderSettingsCredentials;
use App\Domain\Model\ShipmentStatus\ShipmentStatus;
use App\Domain\Model\ShipmentStatus\ShipmentStatusRequestData;
use App\Domain\Model\ShipmentStatus\ShipmentStatusResponseData;
use App\Domain\Model\Shipping\Courier;
use App\Domain\Model\Shipping\CourierOfficeFiltersInterface;
use App\Domain\Model\Shipping\InOut\InOutCourierOfficeFilters;
use App\Domain\Model\Shipping\InOut\InOutReturnDocs;
use App\Domain\Model\Shipping\InOut\InOutShippingGatewayInterface;
use App\Domain\Model\Shipping\InOut\InOutWayBillDataModifierInterface;
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
use App\Infrastructure\Domain\Model\Shipping\CompanyData;
use App\Infrastructure\Domain\Model\Shipping\CourierData;
use App\Infrastructure\Domain\Model\Shipping\CourierOfficeData;
use App\Infrastructure\Domain\Model\Shipping\InOut\WayBillDataModifiers\InOutCityWayBillDataModifier;
use App\Infrastructure\Domain\Model\Shipping\InOut\WayBillDataModifiers\InOutPhoneWayBillDataModifier;
use App\Infrastructure\Exception\GatewayErrorException;
use App\Infrastructure\Exception\TenantProvidersException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

use function Symfony\Component\String\u;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class InOutShippingGateway extends AbstractShippingGateway implements InOutShippingGatewayInterface
{
    private array $wayBillDataModifiers = [
        InOutCityWayBillDataModifier::class,
        InOutPhoneWayBillDataModifier::class,
    ];

    // https://nextbasket.atlassian.net/browse/NB-9371
    private const BUY_ANTIQUE_TENANT_ID = '3fc8015b-aef0-4bb0-b014-6e71177390ae';
    // https://nextbasket.atlassian.net/browse/NB-13265
    private const BALKANI_TEST_TENANT_ID = '57665c84-d70b-4bda-ae75-3599606c05f1';

    private const API_URL = 'https://api1.inout.bg/api/v1/';
    private const API_TEST_TOKEN = 'wVA6pevZOtCBB2ynKClcKfMJUU7lqt5nTCoJ8KIZd6aOUJqox6BZRm0yPP0O';
    private const API_TEST_COMPANY_ID = 333;

    private const SHIPPING_SERVICE_NAME = 'crossborder'; // or eushipmentexpress

    private const URI_GET_COMPANIES = 'get-user-companies';
    private const URI_GET_COMPANY_COURIERS = 'couriers/{companyId}';
    private const URI_GET_COURIER_OFFICES = 'offices-by-courier/{courierId}';
    private const URI_CREATE_WAY_BILL = 'createAWB';
    private const URI_DOWNLOAD_WAY_BILL = 'print/{shippingNumber}';
    private const URI_ESTIMATE_PRICE = 'shipment-price';
    private const URI_WAY_BILL_HISTORY = 'fulfilment/waybills-history';
    private const URI_GET_CITIES = 'get-cities/{countryId}';
    private const BG_COUNTRY_ID = 2;

    private const DELIVERY_TAX_PERCENT = 1.2;

    public function validateCredentials(ProviderSettingsCredentials $credentials): ?ConstraintViolation
    {
        try {
            $this->client->request(
                Request::METHOD_GET,
                self::API_URL.self::URI_GET_COMPANIES,
                [
                    'query' => ['testMode' => false],
                    'auth_bearer' => $credentials->getToken(),
                ]
            );
        } catch (ExceptionInterface) {
            return new ConstraintViolation(
                message: 'Invalid credentials',
            );
        }

        return null;
    }

    public function calculatePrice(ShippingPriceEstimationRequestData $data): ShippingPriceEstimationResponseData|ConstraintViolation
    {
        $settings = $this->getSettings();

        // TODO we have to fine insurance logic, it is under comment, because there is difference in price between waybill and calculate price
        $insuranceAmount = 0;
        $codAmount = $data->getCodAmount();

        if (in_array($this->tenantId, [
            self::BUY_ANTIQUE_TENANT_ID,
            self::BALKANI_TEST_TENANT_ID,
        ])) {
            $insuranceAmount = number_format($data->getInsuranceAmount() * 1.95522, 2, '.', '');
            $codAmount > 0 && $codAmount = number_format($codAmount * 1.95522, 2, '.', '');
        }

        $params = [
            'weight' => max(0.1, NumberHelper::intToFloat($data->getWeight(), 3)),
            'codAmount' => $codAmount,
            'insuranceAmount' => $insuranceAmount, // $data->getInsuranceAmount(),
            'openPackage' => $settings['openPackage'] ?? false,
            'toOffice' => $data->isToOffice(),
            'currency' => $data->getCurrency(),
            'companyId' => $settings['companyId'],
            'courierId' => $settings['courierId'],
            'returnDocs' => $settings['returnDocs'] ?? InOutReturnDocs::Nothing->value,
            'saturdayDelivery' => $settings['saturdayDelivery'] ?? false,
        ];

        $response = $this->request(Request::METHOD_POST, self::URI_ESTIMATE_PRICE, ['json' => $params]);
        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        $price = (float) $response['price'];

        if (in_array($this->tenantId, [
            self::BUY_ANTIQUE_TENANT_ID,
            self::BALKANI_TEST_TENANT_ID,
        ])) {
            $price *= 0.61374;
        } else {
            $price = $this->addShippingPriceTaxes($price);
        }

        return new ShippingPriceEstimationResponseData($price);
    }

    public function createWayBill(OrderShippingData $orderShippingData): CreateWayBillResponseData|ConstraintViolation
    {
        $settings = $this->getSettings();

        $contactName = implode(' ', [
            $orderShippingData->getShippingAddress()->getFirstName(),
            $orderShippingData->getShippingAddress()->getLastName(),
        ]);

        // If you want to create shipment to an office,
        // please add a keyword "to office: " to office name or office address (Courier_Offices_Web_Service_v1.2)
        // e.g. " to office: Русе Николаевска" or " to office: Русе ж.к. ЦЮР ул. Николаевска №109".
        $prefixAddress = $orderShippingData->getShippingAddress()->getOfficeId() ? 'to office: ' : '';

        $codAmount = 0;
        if (PaymentMethod::CashOnDelivery->value === $orderShippingData->getPaymentMethod()) {
            $codAmount = NumberHelper::intToFloat($orderShippingData->getTotalPrice());
        }

        $insuranceAmount = 0;
        // TODO we have to fine insurance logic, it is under comment, because there is difference in price between waybill and calculate price
        // if ($orderShippingData->hasInsurance()) {
        //     $insuranceAmount = NumberHelper::intToFloat($orderShippingData->getTotalPrice());
        // }

        if (in_array($this->tenantId, [
            self::BUY_ANTIQUE_TENANT_ID,
            self::BALKANI_TEST_TENANT_ID,
        ])) {
            $insuranceAmount = NumberHelper::intToFloat($orderShippingData->getTotalPrice() - $orderShippingData->getShippingPrice()) * 1.95522;
            $insuranceAmount = number_format($insuranceAmount, 2, '.', '');

            if ($codAmount > 0) {
                $codAmount *= 1.95522;
                $codAmount = number_format($codAmount, 2, '.', '');
            }
        }

        $productDescription = mb_substr($orderShippingData->getDescription(), 0, 1000);

        $data = [
            'testMode' => false,
            'senderId' => $settings['companyId'],
            'courierId' => $settings['courierId'],
            'waybillAvailableDate' => date('Y-m-d', strtotime('+1day')),
            'serviceName' => $this->getConfigVar('serviceName', self::SHIPPING_SERVICE_NAME),
            'recipient' => [
                'name' => $contactName,
                'countryIsoCode' => Countries::getAlpha2Code($orderShippingData->getShippingAddress()->getCountry()),
                'region' => $orderShippingData->getShippingAddress()->getArea(),
                'cityName' => $orderShippingData->getShippingAddress()->getCity(),
                'zipCode' => $orderShippingData->getShippingAddress()->getPostCode(),
                'streetName' => $prefixAddress.$orderShippingData->getShippingAddress()->getAddress(),
                'addressText' => $orderShippingData->getShippingAddress()->getAddressAdditions(),
                'contactPerson' => $contactName,
                'phoneNumber' => $orderShippingData->getShippingAddress()->getPhone(),
                'email' => $orderShippingData->getCustomerEmail(),
            ],
            'awb' => [
                'parcels' => $orderShippingData->getParcels(),
                'envelopes' => 0, // @TODO We do not send envelopes for now
                'totalWeight' => max(0.01, NumberHelper::intToFloat($orderShippingData->getProductsWeight(), 3)),
                'declaredValue' => $insuranceAmount,
                'bankRepayment' => $codAmount,
                'otherRepayment' => '', // Additional information to COD.
                'observations' => '', // Additional information about the shipment/products. (notes)
                'openPackage' => $orderShippingData->hasOpenCheck() || ($settings['openPackage'] ?? false),
                'referenceNumber' => $orderShippingData->getReference(),
                'products' => $productDescription,
                'fragile' => $orderShippingData->isFragile() || ($settings['isFragile'] ?? false),
                'productsInfo' => $orderShippingData->getNotes(), // Additional product information.
                'piecesInPack' => $orderShippingData->getProductsQuantity(),
                'saturdayDelivery' => $settings['saturdayDelivery'] ?? false,
            ],
            'returnLabel' => [
                'nDaysValid' => 0,
            ],
        ];

        $this->applyWayBillDataModifiers($data);
        $this->checkAddress($data);

        /** @var array{awb: string}|ConstraintViolation $response */
        $response = $this->request(Request::METHOD_POST, self::URI_CREATE_WAY_BILL, ['json' => $data]);
        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        return new CreateWayBillResponseData($response['awb'], [
            'reference' => $orderShippingData->getReference(),
        ]);
    }

    public function downloadWayBill(DownloadWayBillRequestData $downloadWayBillRequestData): DownloadWayBillResponseData|ConstraintViolation
    {
        $uri = str_replace(
            '{shippingNumber}',
            $downloadWayBillRequestData->getShippingNumber(),
            self::URI_DOWNLOAD_WAY_BILL
        );

        /** @var array{type: string, awb_print: string}|ConstraintViolation $response */
        $response = $this->request(Request::METHOD_GET, $uri, ['query' => ['testMode' => false]]);
        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        $fileType = strtolower($response['type']);
        $fileContent = base64_decode($response['awb_print']);

        return new DownloadWayBillResponseData($fileType, $fileContent);
    }

    /**
     * @param OrderShippingData[] $orderShippingDatas
     *
     * @return array<int<0, max>, CreateWayBillResponseData|array{error: bool, reference: string}>
     */
    public function createBulkWayBill(array $orderShippingDatas): array
    {
        $wayBillResponses = [];
        foreach ($orderShippingDatas as $orderShippingData) {
            $response = $this->createWayBill($orderShippingData);

            if ($response instanceof ConstraintViolation) {
                $wayBillResponses[] = [
                    'error' => true,
                    'reference' => $orderShippingData->getReference(),
                ];
                continue;
            }

            $wayBillResponses[] = $response;
        }

        return $wayBillResponses;
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
            $downloadWayBillRequestData = new DownloadWayBillRequestData(
                shippingNumber: $mapWayBillRequestData->getShippingNumber(),
                shippingInfo: $shippingInfo,
            );

            /** @var DownloadWayBillResponseData|ConstraintViolation */
            $wayBill = $this->downloadWayBill($downloadWayBillRequestData);

            if ($wayBill instanceof ConstraintViolation) {
                $violations[] = $wayBill;
                continue;
            }

            $base64Pdf = base64_encode($wayBill->getContent());
            $mappedWayBillResponse[] = new MapWayBillResponseData(
                orderId: $mapWayBillRequestData->getOrderId(),
                reference: $shippingInfo['reference'] ?? '',
                shippingNumber: $mapWayBillRequestData->getShippingNumber(),
                provider: $this->getProviderName(),
                fileType: $this->defaultFileType,
                url: $base64Pdf,
            );
        }

        if (!empty($violations)) {
            return new ConstraintViolationList($violations);
        }

        return $mappedWayBillResponse;
    }

    /**
     * @return CompanyData[]|ConstraintViolation
     */
    public function getCompanies(): array|ConstraintViolation
    {
        $companiesInoutArray = $this->request(Request::METHOD_GET, self::URI_GET_COMPANIES);
        if ($companiesInoutArray instanceof ConstraintViolation) {
            return $companiesInoutArray;
        }

        return array_map(fn (array $companyData) => $this->getCompanyDataFromArray($companyData), $companiesInoutArray);
    }

    /**
     * @return CourierData[]|ConstraintViolation
     */
    public function getCouriers(int $companyId): array|ConstraintViolation
    {
        $uri = str_replace('{companyId}', (string) $companyId, self::URI_GET_COMPANY_COURIERS);
        $couriersInoutArray = $this->request(Request::METHOD_GET, $uri);

        if ($couriersInoutArray instanceof ConstraintViolation) {
            return $couriersInoutArray;
        }

        return array_map(fn (array $courierData) => $this->getCourierDataFromArray($courierData), $couriersInoutArray);
    }

    /**
     * @return CourierOfficeData[]|ConstraintViolation
     */
    public function getCourierOffices(CourierOfficeFiltersInterface $filters): array|ConstraintViolation
    {
        if (!$filters instanceof InOutCourierOfficeFilters) {
            throw new InvalidArgumentException(sprintf('Unexpected filters class type. Expected "%s", got "%s".', InOutCourierOfficeFilters::class, get_class($filters)));
        }

        $uri = str_replace('{courierId}', (string) $filters->getCourierId(), self::URI_GET_COURIER_OFFICES);
        $response = $this->request(Request::METHOD_GET, $uri);
        if ($response instanceof ConstraintViolation) {
            return $response;
        }

        $address = $filters->getAddress();
        $address = u($address)->ascii()->lower()->toString();
        $offices = array_map(fn (array $office) => $this->getCourierOfficeDataFromArray($office), $response);

        return array_filter(
            $offices,
            fn (CourierOfficeData $office) => str_contains(u($office->getAddressEn())->lower()->toString(), $address)
        );
    }

    public function getShipmentStatus(ShipmentStatusRequestData $shipmentStatusRequestData): ShipmentStatusResponseData|ConstraintViolation
    {
        $awbs = array_map(fn ($number) => ['awb' => $number], $shipmentStatusRequestData->getShippingNumbers());
        $data = [
            'testMode' => false,
            'awbs' => $awbs,
        ];

        $response = $this->request(Request::METHOD_POST, self::URI_WAY_BILL_HISTORY, ['json' => $data]);
        if ($response instanceof ConstraintViolation) {
            return $response;
        }
        $result = [];

        foreach ($response as $awb) {
            if (!$awb['errorCode']) {
                $statusesHistory = $awb['statusesHistory'];
                if (count($statusesHistory)) {
                    $lastStatus = $statusesHistory[count($statusesHistory) - 1];
                    $isPaid = 'COD paid' === $lastStatus['STATUS'] || 'Delivered' === $lastStatus['STATUS'];

                    $result[] = new ShipmentStatus(
                        $awb['awb'],
                        $isPaid,
                        $lastStatus['STATUS'],
                    );
                }
            }
        }

        return new ShipmentStatusResponseData($result, new \DateTime());
    }

    public function getStatusMapping(string|int $providerStatus): string
    {
        return match ($providerStatus) {
            'In the office', 'New', 'Stockout', 'Packed', 'Information received', 'Awaiting pickup', 'Insufficient data - AWB not created', 'Information sent to warehouse', 'PreAlert', 'Warehouse', 'Warehouse Budapest', 'Warehouse Sofia', 'Warehouse Zagreb' => WaybillStatus::PENDING->value,
            'On delivery', 'In transit', 'Redirected', 'Warehouse Ruse', 'Returning', 'Scanned Waybill', 'Lastmile Accept' => WaybillStatus::SHIPPED->value,
            'Delivered', 'COD paid', 'Claim opened' => WaybillStatus::DELIVERED->value,
            'Returned', 'Canceled', 'Lost shipment', 'Deleted', 'Compenstaed courier fee', 'Damaged shipment', 'Destroyed', 'Returned to Warehouse', 'Rejected by courier', 'Returned to Client' => WaybillStatus::CANCELED->value,
            default => '',
        };
    }

    /**
     * @throws TenantProvidersException
     */
    public function setProviderSettings(
        ProviderSettings $providerSettings,
        ?array $customSettings = null,
    ): void {
        /** @var array{companyId?: int|string, courierId?: int|string} $settings */
        $settings = $customSettings ?? $providerSettings->getSettings();

        if (!isset($settings['companyId'], $settings['courierId'])) {
            throw new TenantProvidersException('Missing or invalid companyId and/or courierId');
        }

        parent::setProviderSettings($providerSettings, $customSettings);
    }

    protected function handleRequestException(ExceptionInterface $ex): ConstraintViolation
    {
        return new ConstraintViolation('Gateway invalid request');
    }

    /**
     * @throws GatewayErrorException
     */
    protected function getClientOptions(): array
    {
        $testMode = $this->getConfigVar('testMode', true);
        $token = $this->getConfigVar('token');

        if (null === $token) {
            throw new GatewayErrorException('Invalid inout token');
        }

        // If testMode is selected, always use InOut Test token
        if ($testMode) {
            $token = self::API_TEST_TOKEN;
        }

        return [
            'base_uri' => self::API_URL,
            'auth_bearer' => $token,
        ];
    }

    protected function getProviderName(): string
    {
        return 'inout';
    }

    protected function applyWayBillDataModifiers(array &$data): void
    {
        /** @var InOutWayBillDataModifierInterface $dataModifier */
        foreach ($this->wayBillDataModifiers as $dataModifier) {
            (new $dataModifier())($data);
        }
    }

    /**
     * @return array{companyId: int|string, courierId: int|string, openPackage?: ?bool, returnDocs?: ?int, saturdayDelivery?: ?bool, isFragile?: ?bool}
     */
    private function getSettings(): array
    {
        /** @var array{companyId: int|string, courierId: int|string, openPackage?: ?bool, returnDocs?:? int, saturdayDelivery?: ?bool, isFragile?: ?bool} $settings */
        $settings = $this->customSettings ?? $this->providerSettings->getSettings();

        // If testMode is enabled, replace companyId with test companyId
        if ($this->getConfigVar('testMode', true)) {
            $settings['companyId'] = self::API_TEST_COMPANY_ID;
        }

        return $settings;
    }

    private function getCourierDataFromArray(array $courierData): CourierData
    {
        $name = Courier::tryFrom($courierData['ID'])?->getName() ?? $courierData['NAME'];

        return new CourierData(
            $courierData['ID'],
            $name.' (InOut)',
            $courierData['TO_OFFICE'],
            $courierData['TO_ADDRESS'],
        );
    }

    private function getCourierOfficeDataFromArray(array $courier): CourierOfficeData
    {
        return new CourierOfficeData(
            officeId: $courier['ID'],
            officeName: $courier['OFFICE_NAME'],
            officeCode: $courier['COURIER_OFFICE_CODE'] ?? null,
            area: $courier['REGION'],
            cityId: $courier['CITY_ID'],
            cityName: $courier['CITY_NAME'],
            cityCode: $courier['POST_CODE'],
            address: $courier['ADDRESS'] ?? null,
            addressEn: u($courier['ADDRESS'] ?? '')->ascii()->toString(),
        );
    }

    private function getCompanyDataFromArray(array $companyData): CompanyData
    {
        return new CompanyData(
            $companyData['ID'],
            $companyData['NAME'],
            $companyData['BULSTAT'],
            $companyData['ADDRESS'],
            $companyData['MOL'],
        );
    }

    /**
     * Applied to all clients except BuyAntique.
     * should be increased 1.2 times to cover transport costs
     * and VAT should be included - another increase with 1.2.
     *
     * 21-12-2023
     * https://nextbasket.atlassian.net/browse/NB-10814
     */
    private function addShippingPriceTaxes(float $shippingPrice): float
    {
        $shippingPrice *= self::DELIVERY_TAX_PERCENT;

        return NumberHelper::round($shippingPrice);
    }

    private function checkAddress(array &$data): void
    {
        if ('BG' !== $data['recipient']['countryIsoCode']) {
            return;
        }

        $cities = $this->getInOutCities();

        if ($cities instanceof ConstraintViolation) {
            return;
        }

        $needle = str_replace(' CITY', '', strtoupper($data['recipient']['cityName']));

        $foundCity = current(array_filter(
            $cities,
            function ($city) use ($needle): bool {
                if (str_contains(strtoupper($city['CITY_NAME_EN'] ?? ''), $needle)) {
                    return true;
                }

                if (str_contains(strtoupper($city['CITY_NAME_LOCAL'] ?? ''), $needle)) {
                    return true;
                }

                return false;
            },
        ));

        if ($foundCity) {
            $data['recipient']['zipCode'] = $foundCity['POSTAL_CODE'];
        }
    }

    private function getInOutCities(): array|ConstraintViolation
    {
        return $this->request(
            Request::METHOD_GET,
            strtr(self::URI_GET_CITIES, ['{countryId}' => self::BG_COUNTRY_ID]),
            [
                'query' => [
                    'paging' => false,
                ],
            ]
        );
    }
}

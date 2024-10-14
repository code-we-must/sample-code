<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\SenderAddress\SenderAddress;
use App\Domain\Model\ShippingPrice\ShippingPriceEstimationRequestData;
use App\Infrastructure\Service\CountryCodeConverter;

final class BobGoRatesPayloadAssembler
{
    public function __invoke(
        ShippingPriceEstimationRequestData $data,
        array $settings,
        ?SenderAddress $senderAddress,
    ): array {
        if (null === $senderAddress) {
            throw new \Exception('SenderAddress object is null.');
        }

        $parcels = [];
        foreach ($data->getProductDetails() as $orderProductsDetail) {
            $parcels[] = [
                'description' => null,
                'submitted_length_cm' => $settings['defaultLength'] ?? 0,
                'submitted_width_cm' => $settings['defaultWidth'] ?? 0,
                'submitted_height_cm' => $settings['defaultHeight'] ?? 0,
                'submitted_weight_kg' => $orderProductsDetail->getWeight() ?? $settings['defaultWeight'] ?? 0,
            ];
        }

        $shippingAddress = $data->getShippingAddress();

        return [
            'providers' => [$settings['partnerId']],
            'service_levels' => [$settings['defaultServiceCode']],

            'collection_address' => [
                'street_address' => $senderAddress->getStreetNumber().' '.$senderAddress->getStreetName(),
                'company' => $senderAddress->getSenderName(),
                'local_area' => '-',
                'city' => $senderAddress->getCity(),
                'zone' => $senderAddress->getProvince(),
                'country' => CountryCodeConverter::fromAlpha3toAlpha2($senderAddress->getCountryCode()),
                'code' => $senderAddress->getPostCode(),
            ],
            'delivery_address' => [
                'company' => $shippingAddress->getCompanyName(),
                'street_address' => $shippingAddress->getAddress(),
                'local_area' => $shippingAddress->getArea(),
                'city' => $shippingAddress->getCity(),
                'zone' => $shippingAddress->getProvince(),
                'country' => CountryCodeConverter::fromAlpha3toAlpha2($shippingAddress->getCountry()),
                'code' => $shippingAddress->getPostCode(),
            ],
            'parcels' => $parcels,
            'declared_value' => $data->getTotalPrice(),
            'timeout' => 10000,

            'collection_contact_mobile_number' => $senderAddress->getSenderPhone(),
            'collection_contact_email' => null,
            'collection_contact_full_name' => $senderAddress->getSenderName(),

            'delivery_contact_mobile_number' => $shippingAddress->getPhone(),
            'delivery_contact_email' => null,
            'delivery_contact_full_name' => $shippingAddress->getFirstName().' '.$shippingAddress->getLastName(),
        ];
    }
}

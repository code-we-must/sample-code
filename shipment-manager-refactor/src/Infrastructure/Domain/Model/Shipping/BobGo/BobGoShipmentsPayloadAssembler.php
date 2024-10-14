<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Order\OrderShippingData;
use App\Domain\Model\SenderAddress\SenderAddress;
use App\Infrastructure\Service\CountryCodeConverter;

final class BobGoShipmentsPayloadAssembler
{
    /**
     * @throws \Exception
     */
    public function __invoke(
        OrderShippingData $data,
        array $settings,
        ?SenderAddress $senderAddress,
    ): array {
        if (null === $senderAddress) {
            throw new \Exception('SenderAddress object is null.');
        }

        $parcels = [];
        foreach ($data->getOrderProductsDetails() as $orderProductsDetail) {
            $parcels[] = [
                'parcel_description' => null,
                'submitted_length_cm' => $settings['defaultLength'] ?? 0,
                'submitted_width_cm' => $settings['defaultWidth'] ?? 0,
                'submitted_height_cm' => $settings['defaultHeight'] ?? 0,
                'submitted_weight_kg' => $orderProductsDetail->getWeight() ?? $settings['defaultWeight'] ?? 0,
            ];
        }

        $shippingAddress = $data->getShippingAddress();

        return [
            'timeout' => 20000,
            'collection_address' => [
                'street_address' => $senderAddress->getStreetNumber().' '.$senderAddress->getStreetName(),
                'company' => $senderAddress->getSenderName(),
                'local_area' => null,
                'city' => $senderAddress->getCity(),
                'zone' => $senderAddress->getProvince(),
                'country' => CountryCodeConverter::fromAlpha3toAlpha2($senderAddress->getCountryCode()),
                'code' => $senderAddress->getPostCode(),
            ],
            'collection_contact_name' => $senderAddress->getSenderName(),
            'collection_contact_mobile_number' => $senderAddress->getSenderPhone(),
            'collection_contact_email' => null,
            'delivery_address' => [
                'company' => $shippingAddress->getCompanyName(),
                'street_address' => $senderAddress->getStreetNumber().' '.$senderAddress->getStreetName(),
                'local_area' => $shippingAddress->getArea(),
                'city' => $shippingAddress->getCity(),
                'zone' => $shippingAddress->getProvince(),
                'country' => CountryCodeConverter::fromAlpha3toAlpha2($shippingAddress->getCountry()),
                'code' => $shippingAddress->getPostCode(),
            ],
            'delivery_contact_name' => $shippingAddress->getFirstName().' '.$shippingAddress->getLastName(),
            'delivery_contact_mobile_number' => $shippingAddress->getPhone(),
            'delivery_contact_email' => $data->getCustomerEmail(),
            'parcels' => $parcels,
            'declared_value' => $data->getTotalPrice(),
            'custom_tracking_reference' => $data->getReference(),
            'custom_order_number' => $data->getOrderId(),
            'instructions_collection' => null,
            'instructions_delivery' => $data->getNotes(),
            'service_level_code' => $settings['defaultServiceCode'],
            'provider_slug' => $settings['partnerId'],
        ];
    }
}

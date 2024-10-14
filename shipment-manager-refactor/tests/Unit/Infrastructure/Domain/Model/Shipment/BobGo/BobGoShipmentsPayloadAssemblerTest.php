<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Domain\Model\ShippingMethod\PaymentMethod;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoShipmentsPayloadAssembler;
use App\Tests\Shared\Factory\OrderShipmentDataFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\SenderAddressFactory;
use App\Tests\Shared\Factory\ShippingAddressFactory;
use App\Tests\Unit\UnitTestCase;

final class BobGoShipmentsPayloadAssemblerTest extends UnitTestCase
{
    public function testInvokeFailureSenderAddressIsNull(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SenderAddress object is null');

        (new BobGoShipmentsPayloadAssembler())(...$this->getArgsWithNullSenderAddress());
    }

    public function testInvokeSuccessNotUsingDefaultUnits(): void
    {
        $payload = (new BobGoShipmentsPayloadAssembler())(...$this->getArgsWithSettingsHavingNullDefaultUnits());

        $parcel = $payload['parcels'][0];
        $this->assertSame(0, $parcel['submitted_length_cm']);
        $this->assertSame(0, $parcel['submitted_width_cm']);
        $this->assertSame(0, $parcel['submitted_height_cm']);
        $this->assertSame(0, $parcel['submitted_weight_kg']);
    }

    public function testInvokeSuccessUsingDefaultUnits(): void
    {
        $payload = (new BobGoShipmentsPayloadAssembler())(...$this->getArgsWithSettingsHavingDefaultUnits());

        $parcel = $payload['parcels'][0];
        $this->assertSame(1, $parcel['submitted_length_cm']);
        $this->assertSame(2, $parcel['submitted_width_cm']);
        $this->assertSame(3, $parcel['submitted_height_cm']);
        $this->assertSame(4, $parcel['submitted_weight_kg']);
    }

    public function testInvokeSuccess(): void
    {
        $payload = (new BobGoShipmentsPayloadAssembler())(...$this->getArgs());

        $this->assertSame(
            [
                'timeout' => 20000,
                'collection_address' => [
                    'street_address' => '177 Jan Smuts Avenue, Lumley House',
                    'company' => 'Some Company',
                    'local_area' => null,
                    'city' => 'Johannesburg',
                    'zone' => 'Gauteng',
                    'country' => 'ZA',
                    'code' => '2121',
                ],
                'collection_contact_name' => 'Some Company',
                'collection_contact_mobile_number' => '+27731234567',
                'collection_contact_email' => null,
                'delivery_address' => [
                    'company' => 'Takealot Edenvale',
                    'street_address' => '177 Jan Smuts Avenue, Lumley House',
                    'local_area' => 'Wilbart',
                    'city' => 'Johannesburg',
                    'zone' => 'Gauteng',
                    'country' => 'ZA',
                    'code' => '2121',
                ],
                'delivery_contact_name' => 'Amos Burton',
                'delivery_contact_mobile_number' => '+27721234567',
                'delivery_contact_email' => 'georgi@nextbasket.com',
                'parcels' => [
                    [
                        'parcel_description' => null,
                        'submitted_length_cm' => 1,
                        'submitted_width_cm' => 2,
                        'submitted_height_cm' => 3,
                        'submitted_weight_kg' => 2,
                    ],
                ],
                'declared_value' => 0,
                'custom_tracking_reference' => 'as12d3',
                'custom_order_number' => 'ef8bb358-cffd-456d-a548-a3946b13cb6d',
                'instructions_collection' => null,
                'instructions_delivery' => 'Some additional information about shipping.',
                'service_level_code' => 'ECO',
                'provider_slug' => 'sandbox',
            ],
            $payload
        );
    }

    private function getArgsWithNullSenderAddress(): array
    {
        return [
            OrderShipmentDataFactory::getCacheData(
                currency: 'ZAF',
                paymentMethod: PaymentMethod::CashOnDelivery->value,
                totalPrice: 0,
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
                shippingPrice: 0,
                shippingPriceWithoutFees: 0,
                orderProductDetails: OrderShipmentDataFactory::getProductDetails(),
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS,
            null,
            'ECO',
            'sandbox',
        ];
    }

    private function getArgsWithSettingsHavingNullDefaultUnits(): array
    {
        return [
            OrderShipmentDataFactory::getCacheData(
                currency: 'ZAF',
                paymentMethod: PaymentMethod::CashOnDelivery->value,
                totalPrice: 0,
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
                shippingPrice: 0,
                shippingPriceWithoutFees: 0,
                orderProductDetails: OrderShipmentDataFactory::getProductDetails(),
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS_WITH_NULL_DEFAULT_UNITS,
            SenderAddressFactory::getSenderAddressZa(),
            'ECO',
            'sandbox',
        ];
    }

    private function getArgsWithSettingsHavingDefaultUnits(): array
    {
        return [
            OrderShipmentDataFactory::getCacheData(
                currency: 'ZAF',
                paymentMethod: PaymentMethod::CashOnDelivery->value,
                totalPrice: 0,
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
                shippingPrice: 0,
                shippingPriceWithoutFees: 0,
                orderProductDetails: OrderShipmentDataFactory::getProductDetails(),
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS,
            SenderAddressFactory::getSenderAddressZa(),
            'ECO',
            'sandbox',
        ];
    }

    private function getArgs(): array
    {
        return [
            OrderShipmentDataFactory::getCacheData(
                currency: 'ZAF',
                paymentMethod: PaymentMethod::CashOnDelivery->value,
                totalPrice: 0,
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
                shippingPrice: 0,
                shippingPriceWithoutFees: 0,
                orderProductDetails: [OrderShipmentDataFactory::getProductDetail(weight: 2)],
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS,
            SenderAddressFactory::getSenderAddressZa(),
            'ECO',
            'sandbox',
        ];
    }
}

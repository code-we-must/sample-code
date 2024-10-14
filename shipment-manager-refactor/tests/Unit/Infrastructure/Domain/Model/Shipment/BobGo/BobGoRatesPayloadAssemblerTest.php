<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoRatesPayloadAssembler;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\SenderAddressFactory;
use App\Tests\Shared\Factory\ShippingAddressFactory;
use App\Tests\Shared\Factory\ShippingPriceEstimationDataFactory;
use App\Tests\Unit\UnitTestCase;

final class BobGoRatesPayloadAssemblerTest extends UnitTestCase
{
    public function testInvokeFailureSenderAddressIsNull(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SenderAddress object is null');

        (new BobGoRatesPayloadAssembler())(...$this->getArgsWithNullSenderAddress());
    }

    public function testInvokeSuccessNotUsingDefaultUnits(): void
    {
        $payload = (new BobGoRatesPayloadAssembler())(...$this->getArgsWithSettingsHavingNullDefaultUnits());

        $parcel = $payload['parcels'][0];
        $this->assertSame(0, $parcel['submitted_length_cm']);
        $this->assertSame(0, $parcel['submitted_width_cm']);
        $this->assertSame(0, $parcel['submitted_height_cm']);
        $this->assertSame(0, $parcel['submitted_weight_kg']);
    }

    public function testInvokeSuccessUsingDefaultUnits(): void
    {
        $payload = (new BobGoRatesPayloadAssembler())(...$this->getArgs());

        $parcel = $payload['parcels'][0];
        $this->assertSame(1, $parcel['submitted_length_cm']);
        $this->assertSame(2, $parcel['submitted_width_cm']);
        $this->assertSame(3, $parcel['submitted_height_cm']);
        $this->assertSame(4, $parcel['submitted_weight_kg']);
    }

    public function testInvokeSuccess(): void
    {
        $payload = (new BobGoRatesPayloadAssembler())(...$this->getArgs());

        $this->assertSame(
            [
                'providers' => ['sandbox'],
                'service_levels' => ['ECO'],
                'collection_address' => [
                    'street_address' => '177 Jan Smuts Avenue, Lumley House',
                    'company' => 'Some Company',
                    'local_area' => '-',
                    'city' => 'Johannesburg',
                    'zone' => 'Gauteng',
                    'country' => 'ZA',
                    'code' => '2121',
                ],
                'delivery_address' => [
                    'company' => 'Takealot Edenvale',
                    'street_address' => '5 Mountjoy Street',
                    'local_area' => 'Wilbart',
                    'city' => 'Johannesburg',
                    'zone' => 'Gauteng',
                    'country' => 'ZA',
                    'code' => '2121',
                ],
                'parcels' => [
                    [
                        'description' => null,
                        'submitted_length_cm' => 1,
                        'submitted_width_cm' => 2,
                        'submitted_height_cm' => 3,
                        'submitted_weight_kg' => 4,
                    ],
                ],
                'declared_value' => 11099,
                'timeout' => 10000,
                'collection_contact_mobile_number' => '+27731234567',
                'collection_contact_email' => null,
                'collection_contact_full_name' => 'Some Company',
                'delivery_contact_mobile_number' => '+27721234567',
                'delivery_contact_email' => null,
                'delivery_contact_full_name' => 'Amos Burton',
            ],
            $payload,
        );
    }

    private function getArgsWithNullSenderAddress(): array
    {
        return [
            ShippingPriceEstimationDataFactory::getData(
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS,
            null,
        ];
    }

    private function getArgsWithSettingsHavingNullDefaultUnits(): array
    {
        return [
            ShippingPriceEstimationDataFactory::getData(
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS_WITH_NULL_DEFAULT_UNITS,
            SenderAddressFactory::getSenderAddressZa(),
        ];
    }

    private function getArgs(): array
    {
        return [
            ShippingPriceEstimationDataFactory::getData(
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
            ),
            ProvidersFactory::PROVIDER_BOBGO_SETTINGS,
            SenderAddressFactory::getSenderAddressZa(),
        ];
    }
}

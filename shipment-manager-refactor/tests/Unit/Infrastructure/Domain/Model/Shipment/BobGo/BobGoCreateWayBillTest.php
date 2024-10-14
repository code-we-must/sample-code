<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Domain\Model\ShippingMethod\PaymentMethod;
use App\Domain\Model\WayBill\CreateWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoApiEndpointGenerator;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoCreateWayBill;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoShipmentsPayloadAssembler;
use App\Infrastructure\Domain\Model\Shipping\ShippingGatewayProxy;
use App\Tests\Shared\Factory\BobGo\BobGoResponseFileFactory;
use App\Tests\Shared\Factory\OrderShipmentDataFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\SenderAddressFactory;
use App\Tests\Shared\Factory\ShippingAddressFactory;
use App\Tests\Unit\UnitTestCase;

final class BobGoCreateWayBillTest extends UnitTestCase
{
    public function testInvokeSuccess(): void
    {
        [$gatewayProxyMock, $payloadAssemblerMock] = $this->createMockObjects();
        [$orderShippingData, $providerSettings, $senderAddress] = $this->createArgs();

        $gatewayProxyMock->expects($this->once())
            ->method('getProviderSettings')
            ->willReturn($providerSettings);

        $gatewayProxyMock->expects($this->once())
            ->method('getSenderAddress')
            ->with($providerSettings->getSettings())
            ->willReturn(SenderAddressFactory::getSenderAddressZa());
        $payloadAssemblerMock->expects($this->once())
            ->method('__invoke')
            ->with(
                $orderShippingData,
                $providerSettings->getSettings(),
                $senderAddress, )
            ->willReturn(['the-payload']);

        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.sandbox.bobgo.co.za/v2/shipments', ['json' => ['the-payload']])
            ->willReturn($this->getDecodedResponseFileContent(BobGoResponseFileFactory::getShipmentsSuccessResponseFile()));

        $createWayBill = new BobGoCreateWayBill(
            new BobGoApiEndpointGenerator(),
            $payloadAssemblerMock,
        );
        $createWayBill->setGatewayProxy($gatewayProxyMock);

        $createWayBillResponseData = $createWayBill($orderShippingData);

        $this->assertInstanceOf(CreateWayBillResponseData::class, $createWayBillResponseData);
        $this->assertSame('UASSPW7D', $createWayBillResponseData->getShippingNumber());
    }

    private function createMockObjects(): array
    {
        return [
            $this->createMock(ShippingGatewayProxy::class),
            $this->createMock(BobGoShipmentsPayloadAssembler::class),
        ];
    }

    private function createArgs(): array
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
            ProvidersFactory::getProviderSettingsForBobGo(),
            SenderAddressFactory::getSenderAddressZa(),
        ];
    }

    private function getDecodedResponseFileContent(string $path): array
    {
        $parent = str_repeat('..'.DIRECTORY_SEPARATOR, 6);
        $json = (string) file_get_contents(__DIR__."/$parent".$path);

        return json_decode($json, true);
    }
}

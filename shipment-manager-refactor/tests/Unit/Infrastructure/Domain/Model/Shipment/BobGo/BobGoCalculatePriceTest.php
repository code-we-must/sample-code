<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Domain\Model\ShippingPrice\ShippingPriceEstimationResponseData;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoApiEndpointGenerator;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoCalculatePrice;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoRatesPayloadAssembler;
use App\Infrastructure\Domain\Model\Shipping\ShippingGatewayProxy;
use App\Tests\Shared\Factory\BobGo\BobGoResponseFileFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Shared\Factory\SenderAddressFactory;
use App\Tests\Shared\Factory\ShippingAddressFactory;
use App\Tests\Shared\Factory\ShippingPriceEstimationDataFactory;
use App\Tests\Unit\UnitTestCase;

final class BobGoCalculatePriceTest extends UnitTestCase
{
    public function testCalculatePriceFailurePriceIsNull(): void
    {
        [$gatewayProxyMock, $payloadAssemblerMock] = $this->createMockObjects();
        [$estimationRequestData, $providerSettings, $senderAddress] = $this->createArgs();

        $ratesResponse = [];

        $payloadAssemblerMock->expects($this->once())
            ->method('__invoke')
            ->with(
                $estimationRequestData,
                $providerSettings->getSettings(),
                $senderAddress,
            )
            ->willReturn(['the-payload']);

        $gatewayProxyMock->expects($this->once())
            ->method('getProviderSettings')
            ->willReturn($providerSettings);
        $gatewayProxyMock->expects($this->once())
            ->method('getSenderAddress')
            ->with($providerSettings->getSettings())
            ->willReturn(SenderAddressFactory::getSenderAddressZa());
        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.sandbox.bobgo.co.za/v2/rates', ['json' => ['the-payload']])
            ->willReturn($ratesResponse);

        $calculatePrice = new BobGoCalculatePrice(
            new BobGoApiEndpointGenerator(),
            $payloadAssemblerMock,
        );
        $calculatePrice->setGatewayProxy($gatewayProxyMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unable to parse price.');

        $calculatePrice($estimationRequestData);
    }

    public function testCalculatePriceSuccess(): void
    {
        [$gatewayProxyMock, $payloadAssemblerMock] = $this->createMockObjects();
        [$estimationRequestData, $providerSettings, $senderAddress] = $this->createArgs();

        $payloadAssemblerMock->expects($this->once())
            ->method('__invoke')
            ->with(
                $estimationRequestData,
                $providerSettings->getSettings(),
                $senderAddress,
            )
            ->willReturn(['the-payload']);

        $gatewayProxyMock->expects($this->once())
            ->method('getProviderSettings')
            ->willReturn($providerSettings);
        $gatewayProxyMock->expects($this->once())
            ->method('getSenderAddress')
            ->with($providerSettings->getSettings())
            ->willReturn(SenderAddressFactory::getSenderAddressZa());
        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.sandbox.bobgo.co.za/v2/rates', ['json' => ['the-payload']])
            ->willReturn($this->getDecodedResponseFileContent(BobGoResponseFileFactory::getRatesSuccessResponseFile()));

        $calculatePrice = new BobGoCalculatePrice(
            new BobGoApiEndpointGenerator(),
            $payloadAssemblerMock,
        );
        $calculatePrice->setGatewayProxy($gatewayProxyMock);

        $estimationResponseData = $calculatePrice($estimationRequestData);

        $this->assertInstanceOf(ShippingPriceEstimationResponseData::class, $estimationResponseData);
        $this->assertSame(249.25, $estimationResponseData->getPrice());
    }

    private function createMockObjects(): array
    {
        return [
            $this->createMock(ShippingGatewayProxy::class),
            $this->createMock(BobGoRatesPayloadAssembler::class),
        ];
    }

    private function createArgs(): array
    {
        return [
            ShippingPriceEstimationDataFactory::getData(
                shippingAddress: ShippingAddressFactory::getBobGoShippingAddress(),
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

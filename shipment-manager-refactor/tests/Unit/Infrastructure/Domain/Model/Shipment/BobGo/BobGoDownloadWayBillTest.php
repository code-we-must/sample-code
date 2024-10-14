<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Domain\Model\WayBill\DownloadWayBillResponseData;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoApiEndpointGenerator;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoDownloadWayBill;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoWayBillPayloadAssembler;
use App\Infrastructure\Domain\Model\Shipping\ShippingGatewayProxy;
use App\Tests\Shared\Factory\BobGo\BobGoResponseFileFactory;
use App\Tests\Shared\Factory\ProvidersFactory;
use App\Tests\Unit\UnitTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BobGoDownloadWayBillTest extends UnitTestCase
{
    public function testInvokeSuccess(): void
    {
        [$gatewayProxyMock, $httpClientMock, $mockResponseMock] = $this->createMockObjects();
        [$downloadWayBillRequestData] = $this->createArgs();

        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $gatewayProxyMock->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.sandbox.bobgo.co.za/v2/shipments/waybill?tracking_references=UASSPW7D&paper_size=A4&waybill_size=A5&waybills_per_shipment=1&show_order_items=false&show_email_address=false')
            ->willReturn($this->getDecodedResponseFileContent(BobGoResponseFileFactory::getWaybillSuccessResponseFile()));

        $mockResponseMock->expects($this->once())
            ->method('getContent')
            ->with(false)
            ->willReturn('file-content-data');
        $httpClientMock->expects($this->once())
            ->method('request')
            ->with('GET', 'https://bobgo-sandbox-infra-accounts.s3.af-south-1.amazonaws.com/waybills/bob_go_NEX001_waybill_UASSPW7D_A5.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAYII2WHY7YIS6ZRCH%2F20240913%2Faf-south-1%2Fs3%2Faws4_request&X-Amz-Date=20240913T062512Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&x-id=GetObject&X-Amz-Signature=11ed8af6154d4fa91e15a847a0ea3156edade872849af8291c3860f4270cf1f1')
            ->willReturn($mockResponseMock);
        $gatewayProxyMock->expects($this->once())
            ->method('getClient')
            ->willReturn($httpClientMock);

        $downloadWayBill = new BobGoDownloadWayBill(
            new BobGoApiEndpointGenerator(),
            new BobGoWayBillPayloadAssembler()
        );
        $downloadWayBill->setGatewayProxy($gatewayProxyMock);

        $downloadWayBillResponseData = $downloadWayBill($downloadWayBillRequestData);

        $this->assertInstanceOf(DownloadWayBillResponseData::class, $downloadWayBillResponseData);
        $this->assertSame('pdf', $downloadWayBillResponseData->getType());
        $this->assertSame('file-content-data', $downloadWayBillResponseData->getContent());
    }

    private function createMockObjects(): array
    {
        return [
            $this->createMock(ShippingGatewayProxy::class),
            $this->createMock(HttpClientInterface::class),
            $this->createStub(MockResponse::class),
        ];
    }

    private function createArgs(): array
    {
        return [
            ProvidersFactory::getDownloadWayBillRequestData(
                wayBillNumber: 'UASSPW7D',
            ),
        ];
    }

    private function getDecodedResponseFileContent(string $path): array
    {
        $parent = str_repeat('..'.DIRECTORY_SEPARATOR, 6);
        $json = (string) file_get_contents(__DIR__."/$parent".$path);

        return json_decode($json, true);
    }
}

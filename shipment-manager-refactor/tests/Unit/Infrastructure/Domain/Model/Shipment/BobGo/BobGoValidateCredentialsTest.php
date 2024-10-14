<?php

namespace App\Tests\Unit\Infrastructure\Domain\Model\Shipment\BobGo;

use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoApiEndpointGenerator;
use App\Infrastructure\Domain\Model\Shipping\BobGo\BobGoValidateCredentials;
use App\Infrastructure\Domain\Model\Shipping\ShippingGatewayProxy;
use App\Tests\Shared\Factory\BobGo\BobGoResponseFileFactory;
use App\Tests\Unit\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BobGoValidateCredentialsTest extends UnitTestCase
{
    public function testInvokeFailureTokenIsNull(): void
    {
        [$gatewayProxyMock, , , $loggerMock] = $this->createMockObjects();

        $gatewayProxyMock->expects($this->never())
            ->method('getClient');

        $loggerMock->expects($this->never())
            ->method('error');

        $validateCredentials = new BobGoValidateCredentials(
            new BobGoApiEndpointGenerator(),
            $loggerMock,
        );
        $validateCredentials->setGatewayProxy($gatewayProxyMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Token is null');

        $validateCredentials(null);
    }

    public function testInvokeFailureNoResponseMessage(): void
    {
        [$gatewayProxyMock, $httpClientMock, $mockResponseMock, $loggerMock] = $this->createMockObjects();
        $webhooksResponse = '{}';

        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $mockResponseMock->expects($this->once())
            ->method('getContent')
            ->with(false)
            ->willReturn($webhooksResponse);
        $mockResponseMock->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(401);
        $mockResponseMock->expects($this->once())
            ->method('toArray')
            ->with(false)
            ->willReturn([]);
        $httpClientMock->expects($this->once())
            ->method('request')
            ->with(...$this->getRequestArgs('invalid-token'))
            ->willReturn($mockResponseMock);
        $gatewayProxyMock->expects($this->once())
            ->method('getClient')
            ->willReturn($httpClientMock);

        $loggerMock->expects($this->once())
            ->method('error')
            ->with(...$this->getLoggerArgs($webhooksResponse));

        $validateCredentials = new BobGoValidateCredentials(
            new BobGoApiEndpointGenerator(),
            $loggerMock,
        );
        $validateCredentials->setGatewayProxy($gatewayProxyMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BobGo Authentication Error: -');

        $validateCredentials('invalid-token');
    }

    public function testInvokeFailureWithResponseMessage(): void
    {
        [$gatewayProxyMock, $httpClientMock, $mockResponseMock, $loggerMock] = $this->createMockObjects();
        $webhooksResponse = $this->getDecodedResponseFileContent(BobGoResponseFileFactory::getWebhooksFailureResponseFile());

        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $mockResponseMock->expects($this->once())
            ->method('getContent')
            ->with(false)
            ->willReturn($webhooksResponse['message']);
        $mockResponseMock->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(401);
        $mockResponseMock->expects($this->once())
            ->method('toArray')
            ->with(false)
            ->willReturn(['message' => $webhooksResponse['message']]);
        $httpClientMock->expects($this->once())
            ->method('request')
            ->with(...$this->getRequestArgs('invalid-token'))
            ->willReturn($mockResponseMock);
        $gatewayProxyMock->expects($this->once())
            ->method('getClient')
            ->willReturn($httpClientMock);

        $loggerMock->expects($this->once())
            ->method('error')
            ->with(...$this->getLoggerArgs($webhooksResponse['message']));
        $validateCredentials = new BobGoValidateCredentials(
            new BobGoApiEndpointGenerator(),
            $loggerMock,
        );
        $validateCredentials->setGatewayProxy($gatewayProxyMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BobGo Authentication Error: Authentication failed, please check your API key.');

        $validateCredentials('invalid-token');
    }

    public function testInvokeSuccess(): void
    {
        [$gatewayProxyMock, $httpClientMock, $mockResponseMock, $loggerMock] = $this->createMockObjects();

        $gatewayProxyMock->expects($this->once())
            ->method('getConfigVar')
            ->with('testMode')
            ->willReturn(true);
        $mockResponseMock->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $httpClientMock->expects($this->once())
            ->method('request')
            ->with(...$this->getRequestArgs('valid-token'))
            ->willReturn($mockResponseMock);
        $gatewayProxyMock->expects($this->once())
            ->method('getClient')
            ->willReturn($httpClientMock);

        $loggerMock->expects($this->never())
            ->method('error');

        $validateCredentials = new BobGoValidateCredentials(
            new BobGoApiEndpointGenerator(),
            $loggerMock,
        );
        $validateCredentials->setGatewayProxy($gatewayProxyMock);

        $validateCredentials('valid-token');
    }

    private function createMockObjects(): array
    {
        return [
            $this->createMock(ShippingGatewayProxy::class),
            $this->createMock(HttpClientInterface::class),
            $this->createStub(MockResponse::class),
            $this->createMock(LoggerInterface::class),
        ];
    }

    private function getDecodedResponseFileContent(string $path): array
    {
        $parent = str_repeat('..'.DIRECTORY_SEPARATOR, 6);
        $json = (string) file_get_contents(__DIR__."/$parent".$path);

        return json_decode($json, true);
    }

    private function getRequestArgs(string $token): array
    {
        return [
            'GET',
            'https://api.sandbox.bobgo.co.za/v2/webhooks',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer $token",
                ],
            ],
        ];
    }

    private function getLoggerArgs(string $response): array
    {
        return [
            'BobGo Authentication Error',
            [
                'response' => $response,
                'status' => 401,
            ],
        ];
    }
}

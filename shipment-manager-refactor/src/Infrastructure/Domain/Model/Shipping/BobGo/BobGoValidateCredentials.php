<?php

declare(strict_types=1);

namespace App\Infrastructure\Domain\Model\Shipping\BobGo;

use App\Domain\Model\Shipping\BobGo\BobGoApiEndpointGeneratorInterface;
use App\Domain\Model\Shipping\BobGo\BobGoValidateCredentialsInterface;
use App\Infrastructure\Domain\Model\Shipping\ProvidesShippingGatewayProxy;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class BobGoValidateCredentials implements BobGoValidateCredentialsInterface
{
    use ProvidesShippingGatewayProxy;

    public function __construct(
        private readonly BobGoApiEndpointGeneratorInterface $apiEndpointGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Exception|ClientExceptionInterface|DecodingExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface
     */
    public function __invoke(?string $token): void
    {
        if (null === $token) {
            throw new \Exception('Token is null');
        }

        $response = $this->gatewayProxy->getClient()->request(
            Request::METHOD_GET,
            $this->apiEndpointGenerator->getWebhooksUrl(!$this->gatewayProxy->getConfigVar('testMode')),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer $token",
                ],
            ]
        );

        if (Response::HTTP_OK !== $response->getStatusCode()) {
            $message = 'BobGo Authentication Error';
            $this->logger->error($message, [
                'response' => $response->getContent(false),
                'status' => $response->getStatusCode(),
            ]);

            $response = $response->toArray(false);

            $message .= ': '.($response['message'] ?? '-');

            throw new \Exception($message);
        }
    }
}

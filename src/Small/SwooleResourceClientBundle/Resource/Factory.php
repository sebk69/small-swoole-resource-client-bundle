<?php
/*
 * This file is a part of Small Swoole Resource Server
 * Copyright 2025 - Sébastien Kus
 * Under MIT Licence
 */

declare(strict_types=1);

namespace Small\SwooleResourceClientBundle\Resource;

use RuntimeException;
use Small\SwooleResourceClientBundle\Contract\ResourceFactoryInterface;
use Small\SwooleResourceClientBundle\Exception\AlreadyExistsException;
use Small\SwooleResourceClientBundle\Exception\ServerUnavailableException;
use Small\SwooleResourceClientBundle\Exception\UnauthorizedException;
use Small\SwooleResourceClientBundle\Exception\UnknownErrorException;
use Small\SwooleSymfonyHttpClient\Exception\BadRequestException;
use Small\SwooleSymfonyHttpClient\Exception\ClientException;
use Small\SwooleSymfonyHttpClient\Exception\ServerException;
use Small\SwooleSymfonyHttpClient\SwooleHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Factory to create/get Resource objects configured to talk to Small Resource Server.
 *
 * Requires package: small/swoole-symfony-http-client
 */
final class Factory implements ResourceFactoryInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $serverUri,
        private readonly string $apiKey,
        ?HttpClientInterface $httpClient = null
    ) {
        // IMPORTANT : toujours configurer le client conservé par la factory
        $base = $httpClient ?? new SwooleHttpClient();

        $this->httpClient = $base->withOptions([
            'base_uri' => rtrim($this->serverUri, '/'),
            'headers'  => [
                'accept'    => 'application/json',
                'x-api-key' => $this->apiKey,
            ],
            'timeout'  => 60,
        ]);
    }

    /**
     * Create a resource on the server, then return a client Resource proxy.
     *
     * @throws RuntimeException on non-201 response
     */
    public function createResource(string $name, int $timeout): Resource
    {
        try {
            $response = $this->httpClient->request('POST', '/resource', [
                'json' => ['name' => $name, 'timeout' => $timeout],
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new ServerUnavailableException('Failed to contact resource server: '.$e->getMessage(), previous: $e);
        /** @phpstan-ignore-next-line  */
        } catch (BadRequestException $e) {
            switch ($e->getResponse()->getStatusCode()) {
                case 409:
                    throw new AlreadyExistsException('Resource already exists', previous: $e);
                case 401:
                    throw new UnauthorizedException('Forbidden', previous: $e);
                default:
                    throw new UnknownErrorException('Unknown error (' . $e->getResponse()->getStatusCode() . ')', previous: $e);
            }
        }

        if (!in_array($response->getStatusCode(), [200, 201])) {
            $body = '';
            try { $body = $response->getContent(false); } catch (\Throwable) {}
            throw new UnknownErrorException(sprintf(
                'Resource creation failed (HTTP %d): %s',
                $response->getStatusCode(),
                $body
            ));
        }

        return new Resource($name, $this->apiKey, $this->httpClient);
    }

    /**
     * Return a client Resource proxy without creating it server-side.
     */
    public function getResource(string $name): Resource
    {
        return new Resource($name, $this->apiKey, $this->httpClient);
    }
}

<?php
declare(strict_types=1);

namespace Small\SwooleResourceClientBundle\Resource;

use RuntimeException;
use Small\SwooleSymfonyHttpClient\SwooleHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Factory to create/get Resource objects configured to talk to Small Resource Server.
 *
 * Requires package: small/swoole-symfony-http-client
 */
final class Factory
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
            'timeout'  => 10,
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
            throw new RuntimeException('Failed to contact resource server: '.$e->getMessage(), previous: $e);
        }

        if ($response->getStatusCode() !== 201) {
            $body = '';
            try { $body = $response->getContent(false); } catch (\Throwable) {}
            throw new RuntimeException(sprintf(
                'Resource creation failed (HTTP %d): %s',
                $response->getStatusCode(),
                $body
            ));
        }

        return new Resource($name, $this->httpClient);
    }

    /**
     * Return a client Resource proxy without creating it server-side.
     */
    public function getResource(string $name): Resource
    {
        return new Resource($name, $this->httpClient);
    }
}

<?php
/*
 * This file is a part of Small Swoole Resource Server
 * Copyright 2025 - Sébastien Kus
 * Under MIT Licence
 */

declare(strict_types=1);

namespace Small\SwooleResourceClientBundle\Resource;

use Small\SwooleResourceClientBundle\Exception\BadFormatException;
use Small\SwooleResourceClientBundle\Exception\NotFoundException;
use Small\SwooleResourceClientBundle\Exception\NotUpdatedException;
use Small\SwooleResourceClientBundle\Exception\ServerUnavailableException;
use Small\SwooleResourceClientBundle\Exception\EmptyDataException;
use Small\SwooleResourceClientBundle\Exception\UnknownErrorException;
use Small\SwooleSymfonyHttpClient\Exception\BadRequestException;
use Small\SwooleSymfonyHttpClient\Exception\ClientException;
use Small\SwooleSymfonyHttpClient\Exception\ServerException;
use Small\SwooleSymfonyHttpClient\SwooleHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resource client for a single named resource.
 *
 * Server API (from small-resource-server README):
 *   POST   /resource                                  (x-api-key with WRITE)     -> create
 *   GET    /resource/{name}/{selector}?lock=1|0       (READ/LOCK)                -> read (and optional lock)
 *   PUT    /resource/{name}/{selector}                (WRITE + X-Ticket)         -> update data
 *   POST   /resource/{name}/{selector}/unlock         (READ/LOCK + X-Ticket)     -> unlock
 */
final class Resource
{
    private ?string $ticket = null;

    public function __construct(
        private readonly string $name,
        private readonly string $apiKey,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Fetch data for a selector.
     * If $lock = true, will attempt to acquire/keep lock; server may respond 202 with a ticket.
     *
     * @param string $selector
     * @param bool $lock
     * @return mixed
     * @throws BadFormatException
     * @throws BadRequestException
     * @throws EmptyDataException
     * @throws NotFoundException
     * @throws ServerUnavailableException
     * @throws TransportExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     */
    public function getData(string $selector, bool $lock): mixed
    {

        $headers = [];
        if ($this->ticket) {
            $headers['X-Ticket'] = $this->ticket;
        }
        $headers['x-api-key'] = $this->apiKey;

        try {
            $response = $this->httpClient->request('GET', sprintf('/resource/%s/%s', rawurlencode($this->name), rawurlencode($selector)), [
                'query' => ['lock' => $lock ? 1 : 0],
                'headers' => $headers,
            ]);
        } catch (TransportExceptionInterface $e) {
            dump($e);
            throw new ServerUnavailableException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        } catch (ClientException $e) {
            dump($e);
            throw new NotFoundException('Selector not found (' . $selector . ') for resource ' . $this->name, previous: $e);
        } catch (BadRequestException $e) {
            switch ($e->getResponse()->getStatusCode()) {
                case 404:
                    throw new NotFoundException('Resource selector for resource ' . $this->name . '(' . $selector . ')', previous: $e);
            }
        }
        
        // capture/refresh ticket if any
        try {
            $respHeaders = $response->getHeaders(false);
            if (isset($respHeaders['X-Ticket'][0])) {
                $this->ticket = $respHeaders['X-Ticket'][0];
            }
        } catch (\Throwable) {
            dump($respHeaders);
        }

        $status = $response->getStatusCode();
        if (in_array($status, [200])) {
            $content = $response->getContent(false);

            if (empty($content)) {
                return null;
            }

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadFormatException('Invalid JSON returned by resource server: ' . json_last_error_msg());
            }
            $data = json_decode($decoded['data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadFormatException('Invalid JSON data: ' . json_last_error_msg());
            }

            return $data;
        }

        if ($status === 202) {
            throw new EmptyDataException('A waiting ticket have been returned');
        }

        // Other status codes → bubble up a clear error with body for debugging
        $body = '';
        try { $body = $response->getContent(false); } catch (\Throwable) {}
        throw new UnknownErrorException(sprintf(
            'getData failed for "%s" (HTTP %d): %s',
            $selector,
            $status,
            $body
        ));
    }

    /**
     * Attempt to acquire a lock on the selector (no data use, just lock intent).
     * Returns true if the lock is acquired (data available), false if not yet (ticket stored for retry).
     */
    public function lockData(string $selector): bool
    {
        $headers = [];
        if ($this->ticket) {
            $headers['X-Ticket'] = $this->ticket;
        }
        $headers['x-api-key'] = $this->apiKey;
        
        try {
            $response = $this->httpClient->request('PUT', sprintf('/resource/%s/%s/lock', rawurlencode($this->name), rawurlencode($selector)), [
                'headers' => $headers,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new ServerUnavailableException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        } catch (ClientException $e) {
            throw new NotFoundException('Selector not found (' . $selector . ') for resource ' . $this->name, previous: $e);
        } catch (BadRequestException $e) {
            dump($e);
            throw $e;
        }

        // capture/refresh ticket if any
        try {
            $respHeaders = $response->getHeaders(false);
            if (isset($respHeaders['X-Ticket'][0])) {
                $this->ticket = $respHeaders['X-Ticket'][0];
            }
        } catch (\Throwable) {
            // ignore header parsing errors
        }

        $status = $response->getStatusCode();
        if (in_array($status, [200])) {

            $result = json_decode($response->getContent(false), true);

            return $result['locked'] ?? false;
        }

        return false;
    }

    /**
     * Update resource selector content with raw JSON (string).
     * Requires that a ticket has been obtained via getData(lock=true) previously.
     */
    public function writeData(string $selector, mixed $data): bool
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $headers = ['content-type' => 'application/json'];
        if ($this->ticket) {
            $headers['X-Ticket'] = $this->ticket;
        }
        $headers['x-api-key'] = $this->apiKey;

        try {
            $response = $this->httpClient->request('PUT', sprintf('/resource/%s/%s', rawurlencode($this->name), rawurlencode($selector)), [
                'headers' => $headers,
                'body' => $json,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new ServerUnavailableException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        } catch (ServerException $e) {
            var_dump($e->getResponse());
            exit;
        } catch (BadRequestException $e) {
            var_dump($e->getRequest());
            throw $e;
        }

        if (!in_array($response->getStatusCode(),  [200, 204])) {
            $body = '';
            try { $body = $response->getContent(false); } catch (\Throwable) {}
            throw new NotUpdatedException(sprintf(
                'writeData failed for "%s" (HTTP %d): %s',
                $selector,
                $response->getStatusCode(),
                $body
            ));
        }

        return true;
    }

    /**
     * Unlock a selector previously locked with getData(lock=true).
     */
    public function unlockData(string $selector): bool
    {
        $headers = [];
        if ($this->ticket) {
            $headers['X-Ticket'] = $this->ticket;
            $headers['x-api-key'] = $this->apiKey;
        }

        try {
            $response = $this->httpClient->request('PUT', sprintf('/resource/%s/%s/unlock', rawurlencode($this->name), rawurlencode($selector)), [
                'headers' => $headers,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new ServerException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        } catch (BadRequestException $e) {
            var_dump($e->getRequest());
            throw $e;
        }


        $status = $response->getStatusCode();
        if ($status === 200) {
            return true;
        }

        $body = '';
        try { $body = $response->getContent(false); } catch (\Throwable) {}
        throw new UnknownErrorException(sprintf(
            'unlockData failed for "%s" (HTTP %d): %s',
            $selector,
            $status,
            $body
        ));
    }

    /**
     * Expose last server ticket for advanced workflows (optional helper).
     */
    public function getTicket(): ?string
    {
        return $this->ticket;
    }

}

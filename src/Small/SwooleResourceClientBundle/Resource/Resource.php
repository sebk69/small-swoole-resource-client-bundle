<?php
declare(strict_types=1);

namespace Small\SwooleResourceClientBundle\Resource;

use Small\SwooleSymfonyHttpClient\SwooleHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resource client for a single named resource.
 *
 * Server API (from small-resource-server README):
 *   POST   /resource                                  (x-api-key with WRITE)     -> create
 *   GET    /resource/{name}/{selector}?lock=1|0       (READ/LOCK)                -> read (and optional lock)
 *   PUT    /resource/{name}/{selector}                (WRITE + x-ticket)         -> update data
 *   POST   /resource/{name}/{selector}/unlock         (READ/LOCK + x-ticket)     -> unlock
 */
final class Resource
{
    private ?string $ticket = null;

    public function __construct(
        private readonly string $name,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Fetch data for a selector.
     * If $lock = true, will attempt to acquire/keep lock; server may respond 202 with a ticket.
     *
     * @return mixed Returns decoded JSON array on 200, null on 202 (unavailable).
     * @throws RuntimeException on 4xx/5xx other than 404/401/403 where details are useful.
     */
    public function getData(string $selector, bool $lock): mixed
    {
        $headers = [];
        if ($this->ticket) {
            $headers['x-ticket'] = $this->ticket;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('/resource/%s/%s', rawurlencode($this->name), rawurlencode($selector)), [
                'query' => ['lock' => $lock ? 1 : 0],
                'headers' => $headers,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        }

        // capture/refresh ticket if any
        try {
            $respHeaders = $response->getHeaders(false);
            if (isset($respHeaders['x-ticket'][0])) {
                $this->ticket = $respHeaders['x-ticket'][0];
            }
        } catch (\Throwable) {
            // ignore header parsing errors
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            $content = $response->getContent(false);
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON returned by resource server: ' . json_last_error_msg());
            }
            return $decoded;
        }

        if ($status === 202) {
            // not available yet, caller may choose to retry later using stored ticket
            return null;
        }

        // Other status codes → bubble up a clear error with body for debugging
        $body = '';
        try { $body = $response->getContent(false); } catch (\Throwable) {}
        throw new RuntimeException(sprintf(
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
        return $this->getData($selector, true) !== null;
    }

    /**
     * Update resource selector content with raw JSON (string).
     * Requires that a ticket has been obtained via getData(lock=true) previously.
     */
    public function writeData(string $selector, string $json): bool
    {
        $headers = ['content-type' => 'application/json'];
        if ($this->ticket) {
            $headers['x-ticket'] = $this->ticket;
        }

        try {
            $response = $this->httpClient->request('PUT', sprintf('/resource/%s/%s', rawurlencode($this->name), rawurlencode($selector)), [
                'headers' => $headers,
                'body' => $json,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        }

        if ($response->getStatusCode() !== 204) {
            $body = '';
            try { $body = $response->getContent(false); } catch (\Throwable) {}
            throw new RuntimeException(sprintf(
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
            $headers['x-ticket'] = $this->ticket;
        }

        try {
            $response = $this->httpClient->request('POST', sprintf('/resource/%s/%s/unlock', rawurlencode($this->name), rawurlencode($selector)), [
                'headers' => $headers,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('Failed to contact resource server: ' . $e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            return true;
        }

        $body = '';
        try { $body = $response->getContent(false); } catch (\Throwable) {}
        throw new RuntimeException(sprintf(
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

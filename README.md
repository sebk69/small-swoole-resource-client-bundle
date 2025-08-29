# Small Swoole Resource Client Bundle

<img src="img/tests-badge.png" width="200px">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="img/coverage-badge.png" width="200">

A lightweight Symfony bundle that provides a typed client to interact with the
[small-resource-server](https://github.com/sebk69/small-resource-server), using a Swoole-based HTTP client.

- **Namespace:** `Small\SwooleResourceClientBundle`
- **Requires:** PHP ≥ 8.3, Symfony ≥7.2, `small/swoole-symfony-http-client`
- **Config keys:** `server_uri`, `api_key`

## Why

- Acquire and release **resource locks** via a simple API
- Read / write **JSON data** for a named resource + selector
- Fully compatible with Symfony HttpClient contracts; the factory accepts any `HttpClientInterface`

## Installation

```bash
composer require small/swoole-resource-client-bundle
# (this bundle) and its HTTP dependency:
composer require small/swoole-symfony-http-client
```

If Flex does not register it automatically, enable the bundle in `config/bundles.php`:

```php
return [
    Small\SwooleResourceClientBundle\SmallSwooleResourceClientBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/small_swoole_resource_client.yaml`:

```yaml
small_swoole_resource_client:
  server_uri: 'http://localhost:9501'  # resource server base URL
  api_key: '%env(RESOURCE_API_KEY)%'   # key that the server expects in "x-api-key"
```

> **Tip:** provide `RESOURCE_API_KEY` through your secrets or environment.

## Services

The bundle exposes a single public service:

- `small_swoole_resource_client.factory` → `Small\SwooleResourceClientBundle\Resource\Factory`

### Factory

```php
final class Factory
{
    public function __construct(string $serverUri, string $apiKey, ?HttpClientInterface $httpClient = null);
    public function createResource(string $name, int $timeout): Resource; // POST /resource
    public function getResource(string $name): Resource;                  // client-side proxy only
}
```

- If no client is injected, the factory builds a `Small\SwooleSymfonyHttpClient\SwooleHttpClient`
  and configures it with:
  - `base_uri = rtrim(serverUri, '/')`
  - default headers: `accept: application/json` and `x-api-key: <api_key>`
  - `timeout: 10`

### Resource

```php
final class Resource
{
    public function __construct(string $name, HttpClientInterface $client);
    public function getData(string $selector, bool $lock): array|null; // GET /resource/{name}/{selector}?lock=0|1
    public function lockData(string $selector): bool;                  // convenience wrapper over getData(..., true)
    public function writeData(string $selector, string $json): bool;   // PUT /resource/{name}/{selector}
    public function unlockData(string $selector): bool;                // POST /resource/{name}/{selector}/unlock
    public function getTicket(): ?string;                              // last x-ticket, if provided by server
}
```

- When the server returns **202 Accepted**, the client stores the `x-ticket` header and returns `null`.
- On **200 OK**, `getData()` returns the decoded JSON array.
- `writeData()` requires the `x-ticket` header (set automatically if previously received).
- `unlockData()` returns `true` on **200 OK**.

## Usage Example

```php
use Small\SwooleResourceClientBundle\Resource\Factory;

final class ExampleService
{
    public function __construct(private readonly Factory $factory) {}

    public function run(): void
    {
        $res = $this->factory->createResource('printer', 300);

        // Try to acquire lock & get data
        $data = $res->getData('queue', true);
        if ($data === null) {
            // 202 Accepted: lock pending; you may retry later (ticket is kept inside the Resource).
            return;
        }

        // Process and write a new state
        $res->writeData('queue', json_encode(['status' => 'done'], JSON_THROW_ON_ERROR));
        $res->unlockData('queue');
    }
}
```

## Testing

This repository uses **Pest** for tests. The code is written against `HttpClientInterface` which makes it
easy to inject a fake client in `test` env.

### Run the suite

```bash
composer install
composer test
# or directly: vendor/bin/pest
```

### Feature tests (Kernel)

In `config/packages/test/small_swoole_resource_client.yaml`:

```yaml
small_swoole_resource_client:
  server_uri: 'http://server.example:9501'
  api_key: 'SECRET_KEY'
```

Inject a fake client via `config/services_test.yaml` (or `services_test.php`):

```yaml
services:
  Tests\FakeClass\DummySwooleHttpClient: { public: true }

  small_swoole_resource_client.factory:
    class: Small\SwooleResourceClientBundle\Resource\Factory
    public: true
    arguments:
      $serverUri: '%small_swoole_resource_client.server_uri%'
      $apiKey: '%small_swoole_resource_client.api_key%'
      $httpClient: '@Tests\FakeClass\DummySwooleHttpClient'
```

## Static Analysis

We recommend **PHPStan** (at the highest level your codebase supports).

```bash
composer phpstan
# or vendor/bin/phpstan analyse
```

## Error Handling

All network/transport failures are wrapped in `RuntimeException` with a clear message.
For non-success HTTP statuses, the response body (if any) is included in the exception message
to aid diagnostics.

## Security

This bundle forwards `x-api-key` to the resource server. Treat that key as a secret.
Consider scoping the key with minimal privileges when your server supports it.

## License

Distributed under the **MIT** license. See [LICENSE](LICENSE) for details.

---

**Author:** Sébastien Kus  
**Contact:** sebastien.kus@gmail.com

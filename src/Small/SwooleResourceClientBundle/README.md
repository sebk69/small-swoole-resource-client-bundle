# Small Swoole Resource Client Bundle

Symfony bundle providing a simple client for the [small-resource-server].

## Install dependency

```bash
composer require small/swoole-symfony-http-client
```

## Configure (config/packages/small_swoole_resource_client.yaml)

```yaml
small_swoole_resource_client:
  server_uri: 'http://localhost:9501'
  api_key: '%env(RESOURCE_WRITE)%'
```

## Use

```php
use Small\SwooleResourceClientBundle\Resource\Factory;

public function __construct(private Factory $factory) {}

public function example(): void {
    $printer = $this->factory->createResource('printer', 300);
    // Try to lock & read
    $data = $printer->getData('queue', true);
    if ($data === null) {
        // 202 Accepted, not yet available, try again later (ticket is stored on the Resource)
    } else {
        // ... use $data (array)
        // Update then unlock
        $printer->writeData('queue', json_encode(['status' => 'done']));
        $printer->unlockData('queue');
    }
}
```

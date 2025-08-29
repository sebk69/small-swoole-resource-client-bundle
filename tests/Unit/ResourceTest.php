<?php

use Tests\FakeClass\DummySwooleHttpClient;
use Tests\FakeClass\DummyResponse;
use Small\SwooleResourceClientBundle\Resource\Resource;

function makeResource(DummySwooleHttpClient $client, string $name = 'printer'): array {
    $http = $client->withOptions(['base_uri' => 'http://localhost:9501']);
    // return both the Resource and the http client actually used
    return [new Resource($name, $http), $http];
}

test('getData returns array on 200 and captures x-ticket', function (): void {
    $client = new DummySwooleHttpClient();
    $payload = json_encode(['ok' => true, 'n' => 1]);
    $client->addResponse(new Tests\FakeClass\DummyResponse(200, $payload, ['x-ticket' => 'abc123']));

    [$resource, $http] = makeResource($client);

    $data = $resource->getData('queue', false);

    expect($data)->toBeArray()
        ->and($data['ok'])->toBeTrue()
        ->and($resource->getTicket())->toBe('abc123');

    $call = $http->calls[0];  // <-- assert on the cloned client
    expect($call['method'])->toBe('GET')
        ->and($call['url'])->toBe('/resource/printer/queue')
        ->and($call['options']['query'])->toBe(['lock' => 0]);
});
test('getData returns null on 202 and stores ticket for later use', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['x-ticket' => 'tk-202']));

    [$resource, $http] = makeResource($client);
    $data = $resource->getData('queue', true);

    expect($data)->toBeNull()
        ->and($resource->getTicket())->toBe('tk-202');
});

test('getData throws on non-200/202 with body for diagnostics', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(409, 'conflict body', []));

    [$resource, $http] = makeResource($client);
    $fn = fn() => $resource->getData('queue', false);
    expect($fn)->toThrow(RuntimeException::class);
});

test('lockData returns true when data arrives, false when pending', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['x-ticket' => 't1']));
    $client->addResponse(new DummyResponse(200, json_encode(['v' => 42]), ['x-ticket' => 't1']));

    [$resource, $http] = makeResource($client);
    expect($resource->lockData('queue'))->toBeFalse();
    expect($resource->lockData('queue'))->toBeTrue();
});

test('writeData sends PUT with x-ticket and succeeds on 204', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['x-ticket' => 'lock-ticket']));
    $client->addResolver(function ($method, $url, $options) {
        expect(strtoupper($method))->toBe('PUT');
        expect($options['headers']['x-ticket'] ?? null)->toBe('lock-ticket');
        expect($url)->toBe('/resource/printer/queue');
        return new DummyResponse(204, '');
    });

    [$resource, $http] = makeResource($client);
    $resource->getData('queue', true);
    $ok = $resource->writeData('queue', json_encode(['status' => 'done']));
    expect($ok)->toBeTrue();
});

test('writeData throws on non-204', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['x-ticket' => 'tkt']));
    $client->addResponse(new DummyResponse(400, 'bad request'));

    [$resource, $http] = makeResource($client);
    $resource->getData('sel', true);
    $fn = fn() => $resource->writeData('sel', '{"x":1}');
    expect($fn)->toThrow(RuntimeException::class);
});

test('unlockData succeeds on 200 and uses ticket header if present', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['x-ticket' => 'unlock-ticket']));
    $client->addResolver(function ($method, $url, $options) {
        expect(strtoupper($method))->toBe('POST');
        expect($url)->toBe('/resource/printer/queue/unlock');
        expect($options['headers']['x-ticket'] ?? null)->toBe('unlock-ticket');
        return new DummyResponse(200, '');
    });

    [$resource, $http] = makeResource($client);
    $resource->getData('queue', true);
    expect($resource->unlockData('queue'))->toBeTrue();
});

test('unlockData throws on non-200', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['x-ticket' => 'unlock-ticket']));
    $client->addResponse(new DummyResponse(404, 'not found'));

    [$resource, $http] = makeResource($client);
    $resource->getData('queue', true);
    $fn = fn() => $resource->unlockData('queue');
    expect($fn)->toThrow(RuntimeException::class);
});
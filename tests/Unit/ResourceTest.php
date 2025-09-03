<?php

use Tests\FakeClass\DummySwooleHttpClient;
use Tests\FakeClass\DummyResponse;
use Small\SwooleResourceClientBundle\Resource\Resource;

function makeResource(DummySwooleHttpClient $client, string $name = 'printer'): array {
    $http = $client->withOptions(['base_uri' => 'http://localhost:9501']);
    // return both the Resource and the http client actually used
    return [new Resource($name, 'abc', $http), $http];
}

test('getData returns array on 200 and captures X-Ticket', function (): void {
    $client = new DummySwooleHttpClient();
    $payload = json_encode(['data' => json_encode(['ok' => true]), 'n' => 1]);
    $client->addResponse(new Tests\FakeClass\DummyResponse(200, $payload, ['X-Ticket' => 'abc123']));

    [$resource, $http] = makeResource($client);

    $data = $resource->getData('queue', true);

    expect($data)->toBeArray()
        ->and($data['ok'])->toBeTrue()
        ->and($resource->getTicket())->toBe('abc123');

    $call = $http->calls[0];  // <-- assert on the cloned client
    expect($call['method'])->toBe('GET')
        ->and($call['url'])->toBe('/resource/printer/queue')
        ->and($call['options']['query'])->toBe(['lock' => 1]);
});

test('getData throws on non-200/202 with body for diagnostics', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(409, 'conflict body', []));

    [$resource, $http] = makeResource($client);
    $fn = fn() => $resource->getData('queue', false);
    expect($fn)->toThrow(\Small\SwooleResourceClientBundle\Exception\UnknownErrorException::class);
});

test('lockData returns true when data arrives, false when pending', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['X-Ticket' => 't1']));
    $client->addResponse(new DummyResponse(200, json_encode(['locked' => true]), ['X-Ticket' => 't1']));

    [$resource, $http] = makeResource($client);
    expect($resource->lockData('queue'))->toBeFalse();
    expect($resource->lockData('queue'))->toBeTrue();
});

test('writeData sends PUT with X-Ticket and succeeds on 204', function (): void {
    $client = new DummySwooleHttpClient();
    $payload = json_encode(['data' => json_encode(['ok' => true]), 'n' => 1]);
    $client->addResponse(new Tests\FakeClass\DummyResponse(200, $payload, ['X-Ticket' => 'lock-ticket']));
    $client->addResolver(function ($method, $url, $options) {
        expect(strtoupper($method))->toBe('PUT');
        expect($options['headers']['X-Ticket'] ?? null)->toBe('lock-ticket');
        expect($url)->toBe('/resource/printer/queue');
        return new DummyResponse(204, '');
    });

    [$resource, $http] = makeResource($client);
    try {
        $resource->getData('queue', true);
    } catch (\Small\SwooleResourceClientBundle\Exception\EmptyDataException) {}
    $ok = $resource->writeData('queue', json_encode(['status' => 'done']));
    expect($ok)->toBeTrue();
});

test('writeData throws on non-204', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['X-Ticket' => 'tkt']));
    $client->addResponse(new DummyResponse(400, 'bad request'));

    [$resource, $http] = makeResource($client);
    try {
        $resource->getData('sel', true);
    } catch (\Small\SwooleResourceClientBundle\Exception\EmptyDataException) {}
    $fn = fn() => $resource->writeData('sel', '{"x":1}');
    expect($fn)->toThrow(\Small\SwooleResourceClientBundle\Exception\NotUpdatedException::class);
});

test('unlockData succeeds on 200 and uses ticket header if present', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['X-Ticket' => 'unlock-ticket']));
    $client->addResolver(function ($method, $url, $options) {
        expect(strtoupper($method))->toBe('PUT');
        expect($url)->toBe('/resource/printer/queue/unlock');
        expect($options['headers']['X-Ticket'] ?? null)->toBe('unlock-ticket');
        return new DummyResponse(200, '');
    });

    [$resource, $http] = makeResource($client);
    try {
        $resource->getData('queue', true);
    } catch (\Small\SwooleResourceClientBundle\Exception\EmptyDataException) {}
    expect($resource->unlockData('queue'))->toBeTrue();
});

test('unlockData throws on non-200', function (): void {
    $client = new DummySwooleHttpClient();
    $client->addResponse(new DummyResponse(202, '', ['X-Ticket' => 'unlock-ticket']));
    $client->addResponse(new DummyResponse(404, 'not found'));

    [$resource, $http] = makeResource($client);
    try {
        $resource->getData('queue', true);
    } catch (\Small\SwooleResourceClientBundle\Exception\EmptyDataException) {}
    $fn = fn() => $resource->unlockData('queue');
    expect($fn)->toThrow(\Small\SwooleResourceClientBundle\Exception\UnknownErrorException::class);
});
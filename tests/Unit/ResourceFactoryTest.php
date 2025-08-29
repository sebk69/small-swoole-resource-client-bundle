<?php
// tests/Unit/FactoryTest.php

use Small\SwooleResourceClientBundle\Resource\Factory;
use Small\SwooleResourceClientBundle\Resource\Resource;
use Tests\FakeClass\DummySwooleHttpClient;
use Tests\FakeClass\DummyResponse;

beforeEach(function () {
    // Reset the registry between tests
    DummySwooleHttpClient::$instances = [];
});

function lastClient(): \Tests\FakeClass\DummySwooleHttpClient {
    $idx = array_key_last(\Tests\FakeClass\DummySwooleHttpClient::$instances);
    if ($idx === null) {
        throw new \RuntimeException('No DummySwooleHttpClient instances were created.');
    }
    return \Tests\FakeClass\DummySwooleHttpClient::$instances[$idx];
}

test('Factory configures HttpClient with base_uri and x-api-key', function () {
    // Given
    $factory = new Factory('http://localhost:9501', 'KEY'); // builds real client

    $dummy = (new DummySwooleHttpClient())->withOptions(['base_uri' => 'http://localhost:9501']);

// swap it in
    $ref = new \ReflectionClass($factory);
    $prop = $ref->getProperty('httpClient');
    $prop->setAccessible(true);
    $prop->setValue($factory, $dummy);

// now exercise the factory
    $dummy->addResponse(new DummyResponse(201, ''));
    $res = $factory->createResource('printer', 300);

    expect($res)->toBeInstanceOf(Resource::class);
    $call = $dummy->calls[0];
    expect($call['method'])->toBe('POST')
        ->and($call['url'])->toBe('/resource')
        ->and($call['options']['json'])->toBe(['name' => 'printer', 'timeout' => 300]);
});

test('createResource POSTs /resource with name and timeout, returns Resource on 201', function () {
    // Given
    $factory = new Factory('http://localhost:9501', 'KEY'); // builds real client

    $dummy = (new DummySwooleHttpClient())->withOptions(['base_uri' => 'http://localhost:9501']);

// swap it in
    $ref = new \ReflectionClass($factory);
    $prop = $ref->getProperty('httpClient');
    $prop->setAccessible(true);
    $prop->setValue($factory, $dummy);

// now exercise the factory
    $dummy->addResponse(new DummyResponse(201, ''));
    $res = $factory->createResource('printer', 300);
    $http = lastClient();

    // Server returns 201 Created
    $http->addResponse(new DummyResponse(201, ''));

    // When
    $res = $factory->createResource('printer', 300);

    // Then
    expect($res)->toBeInstanceOf(Resource::class);

    // Assert the recorded call
    $call = $http->calls[0] ?? null;
    expect($call)->not->toBeNull()
        ->and($call['method'])->toBe('POST')
        ->and($call['url'])->toBe('/resource')
        ->and($call['options']['json'])->toBe(['name' => 'printer', 'timeout' => 300]);
});

test('createResource throws with diagnostic message when server returns non-201', function () {
    // Given
    $factory = new Factory('http://localhost:9501', 'KEY'); // builds real client

    $dummy = (new DummySwooleHttpClient())->withOptions(['base_uri' => 'http://localhost:9501']);

// swap it in
    $ref = new \ReflectionClass($factory);
    $prop = $ref->getProperty('httpClient');
    $prop->setAccessible(true);
    $prop->setValue($factory, $dummy);

// now exercise the factory
    $dummy->addResponse(new DummyResponse(201, ''));
    $res = $factory->createResource('printer', 300);
    $http = lastClient();

    $http->addResponse(new DummyResponse(409, 'conflict'));

    // When / Then
    $fn = fn() => $factory->createResource('printer', 300);
    expect($fn)->toThrow(RuntimeException::class);

    // Optional: you can also inspect the first recorded call like above if you want
});

test('getResource returns Resource without making any HTTP call', function () {
    // Given
    $factory = new Factory('http://localhost:9501', 'KEY'); // builds real client

    $dummy = (new DummySwooleHttpClient())->withOptions(['base_uri' => 'http://localhost:9501']);

// swap it in
    $ref = new \ReflectionClass($factory);
    $prop = $ref->getProperty('httpClient');
    $prop->setAccessible(true);
    $prop->setValue($factory, $dummy);

// now exercise the factory
    $dummy->addResponse(new DummyResponse(201, ''));
    $res = $factory->createResource('printer', 300);
    $http = lastClient();
    $initialCount = count($http->calls);

    // When
    $res = $factory->getResource('printer');

    // Then
    expect($res)->toBeInstanceOf(Resource::class)
        ->and(count($http->calls))->toBe($initialCount); // no request performed
});

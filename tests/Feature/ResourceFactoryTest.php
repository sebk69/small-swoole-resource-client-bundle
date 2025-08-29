<?php
declare(strict_types=1);

use Small\SwooleResourceClientBundle\Resource\Factory;
use Small\SwooleResourceClientBundle\Resource\Resource;
use Tests\FakeClass\DummySwooleHttpClient;
use Tests\FakeClass\DummyResponse;

/**
 * Helper: récupère le client HTTP réellement utilisé par la Factory (le clone retourné par withOptions()).
 */
function factoryHttpClient(Factory $factory): DummySwooleHttpClient {
    $ref  = new ReflectionClass($factory);
    $prop = $ref->getProperty('httpClient');
    $prop->setAccessible(true);
    $http = $prop->getValue($factory);

    expect($http)->toBeInstanceOf(DummySwooleHttpClient::class);

    /** @var DummySwooleHttpClient $http */
    return $http;
}

test('factory service is available from the kernel container', function () {
    /** @var Factory $factory */
    $factory = container()->get('small_swoole_resource_client.factory');

    expect($factory)->toBeInstanceOf(Factory::class);

    // Vérifie que la Factory a appliqué base_uri / headers / timeout sur le clone injecté
    $http = factoryHttpClient($factory);
    expect($http->options['base_uri']        ?? null)->toBe('http://server.example:9501')
        ->and($http->options['headers']['x-api-key'] ?? null)->toBe('SECRET_KEY')
        ->and($http->options['headers']['accept']    ?? null)->toBe('application/json')
        ->and($http->options['timeout']              ?? null)->toBe(10);
});

test('createResource posts /resource and returns a Resource on 201', function () {
    /** @var Factory $factory */
    $factory = container()->get('small_swoole_resource_client.factory');
    $http    = factoryHttpClient($factory);

    // Prépare la réponse 201
    $http->calls = [];
    $http->addResponse(new DummyResponse(201, ''));

    $res = $factory->createResource('printer', 300);

    expect($res)->toBeInstanceOf(Resource::class);

    $call = $http->calls[0] ?? null;
    expect($call)->not->toBeNull()
        ->and($call['method'])->toBe('POST')
        ->and($call['url'])->toBe('/resource')
        ->and($call['options']['json'])->toBe(['name' => 'printer', 'timeout' => 300]);
});

test('createResource throws on non-201 and exposes body for diagnostics', function () {
    /** @var Factory $factory */
    $factory = container()->get('small_swoole_resource_client.factory');
    $http    = factoryHttpClient($factory);

    $http->calls = [];
    $http->addResponse(new DummyResponse(409, 'conflict'));

    expect(fn() => $factory->createResource('printer', 300))
        ->toThrow(RuntimeException::class);
});

test('getResource returns a Resource without performing any HTTP call', function () {
    /** @var Factory $factory */
    $factory = container()->get('small_swoole_resource_client.factory');
    $http    = factoryHttpClient($factory);

    $n = count($http->calls);
    $res = $factory->getResource('printer');

    expect($res)->toBeInstanceOf(Resource::class)
        ->and(count($http->calls))->toBe($n); // pas d'appel réseau
});

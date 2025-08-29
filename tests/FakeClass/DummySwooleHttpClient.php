<?php
namespace Tests\FakeClass;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class DummySwooleHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    public array $options = [];

    /** @var list<callable|string|DummyResponse> */
    private array $queue = [];

    /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
    public array $calls = [];

    /** @var list<self> */
    public static array $instances = [];

    public function __construct()
    {
        self::$instances[] = $this;
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->options = array_replace($this->options, $options);
        self::$instances[] = $clone; // <-- track the configured clone too
        return $clone;
    }

    public function addResponse(DummyResponse $r): void { $this->queue[] = $r; }
    public function addResolver(callable $resolver): void { $this->queue[] = $resolver; }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'options' => $options];

        if (!$this->queue) {
            throw new \RuntimeException('No response queued for DummySwooleHttpClient');
        }
        $item = array_shift($this->queue);

        if (is_callable($item)) {
            $resp = $item($method, $url, $options);
            if (!$resp instanceof DummyResponse) {
                throw new \RuntimeException('Resolver must return DummyResponse');
            }
            return $resp;
        }

        if ($item instanceof DummyResponse) {
            return $item;
        }

        throw new \RuntimeException('Unsupported queued item type');
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \Exception('Not implemented');
    }
}

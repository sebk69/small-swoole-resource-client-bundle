<?php
// tests/Double/DummyResponse.php
namespace Tests\FakeClass;

use Symfony\Contracts\HttpClient\ResponseInterface;

final class DummyResponse implements ResponseInterface
{
    /**
     * @param int $statusCode
     * @param string $content
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(
        private int $statusCode,
        private string $content = '',
        private array $headers = []
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(bool $throw = true): string
    {
        return $this->content;
    }

    /**
     * @param bool $throw
     * @return array<string, string|array<int, string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        $out = [];
        foreach ($this->headers as $k => $v) {
            $key = strtolower($k);
            $out[$key] = is_array($v) ? array_values($v) : [$v];
        }
        return $out;
    }

    /**
     * @param bool $throw
     * @return array<array-key, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}

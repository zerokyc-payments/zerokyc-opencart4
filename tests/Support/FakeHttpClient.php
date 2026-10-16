<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests\Support;

use Throwable;
use ZeroKYC\Exception\NetworkException;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Http\Response;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<Response|Throwable> */
    private array $queue = [];

    /**
     * @var list<array{
     *   method: string,
     *   url: string,
     *   headers: array<string, string>,
     *   body: ?string,
     *   timeout: ?float
     * }>
     */
    public array $requests = [];

    /**
     * @param array<string, mixed>  $jsonBody
     * @param array<string, string> $headers
     */
    public function pushJson(int $status, array $jsonBody, array $headers = []): void
    {
        $this->queue[] = new Response(
            $status,
            (string) json_encode($jsonBody, JSON_UNESCAPED_SLASHES),
            $headers
        );
    }

    public function pushException(Throwable $exception): void
    {
        $this->queue[] = $exception;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?float $timeout = null,
    ): Response {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
            'timeout' => $timeout,
        ];

        if ($this->queue === []) {
            throw new NetworkException('FakeHttpClient queue is empty for ' . $method . ' ' . $url);
        }

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

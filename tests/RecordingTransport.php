<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An ingest double cheap enough to build hundreds of: every `request()` runs
 * `$onRequest` (method, url, Guzzle options), which returns the response or
 * throws. The logger only ever calls `request()`.
 */
final class RecordingTransport implements ClientInterface
{
    /** @param \Closure(string, string, array<string, mixed>): ResponseInterface $onRequest */
    public function __construct(private readonly \Closure $onRequest)
    {
    }

    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        return ($this->onRequest)($method, (string) $uri, $options);
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        throw new \LogicException('not used by the logger');
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new \LogicException('not used by the logger');
    }

    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new \LogicException('not used by the logger');
    }

    public function getConfig(?string $option = null)
    {
        return null;
    }
}

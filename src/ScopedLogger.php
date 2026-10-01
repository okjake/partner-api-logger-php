<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * The logger `child()` returns, and the one `runWithContext()` hands its
 * callable (PAPI-5336; PHP parity FLT-1301).
 *
 * Every method runs on the ROOT logger with this scope active, so the buffer,
 * transport, counters, `onError` and shutdown hook stay single — a copy would
 * split them, and two buffers draining independently is precisely the kind of
 * divergence the shared counters exist to rule out. Only the scope differs.
 * It plays the role of the TypeScript SDK's `Proxy` around the logger.
 *
 * It is a `Logger` so it can go anywhere one is type-hinted, but it never runs
 * `Logger`'s constructor, so it carries no logger state of its own: EVERY
 * public `Logger` method must be overridden here to delegate.
 * `ScopedLoggerTest::testEveryPublicLoggerMethodIsDelegated` fails the build
 * if one is added to `Logger` and not here.
 *
 * @internal Obtain one from `Logger::child()`; never construct it directly.
 */
final class ScopedLogger extends Logger
{
    /**
     * Deliberately does not call the parent constructor — see the class
     * docblock.
     */
    public function __construct(
        private readonly Logger $root,
        private readonly RequestScope $scope,
    ) {
    }

    public function setContext(array $fields): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->setContext($fields));
    }

    public function runWithContext(array $context, callable $fn): mixed
    {
        return $this->root->withScope($this->scope, fn () => $this->root->runWithContext($context, $fn));
    }

    public function child(array $context = []): Logger
    {
        return $this->root->withScope($this->scope, fn () => $this->root->child($context));
    }

    public function upstream(array $call): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->upstream($call));
    }

    public function upstreamMiddleware(string $name, array $options = []): callable
    {
        // Bound for good, not just for this call: every request sent through
        // the middleware records into this child's scope, wherever it is sent
        // from.
        return $this->root->makeUpstreamMiddleware($name, $options, $this->scope);
    }

    public function info(string $apiKey, string $message, array $data = []): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->info($apiKey, $message, $data));
    }

    public function warn(string $apiKey, string $message, array $data = []): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->warn($apiKey, $message, $data));
    }

    public function error(string $apiKey, string $message, array $data = []): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->error($apiKey, $message, $data));
    }

    public function debug(string $apiKey, string $message, array $data = []): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->debug($apiKey, $message, $data));
    }

    public function logRequest(string $apiKey, array $request): string
    {
        return $this->root->withScope($this->scope, fn () => $this->root->logRequest($apiKey, $request));
    }

    public function logResponse(string $apiKey, array $response): void
    {
        $this->root->withScope($this->scope, fn () => $this->root->logResponse($apiKey, $response));
    }

    public function flush(): void
    {
        $this->root->flush();
    }

    public function shutdown(): void
    {
        $this->root->shutdown();
    }

    public function close(): void
    {
        $this->root->close();
    }

    public function stats(): array
    {
        return $this->root->stats();
    }

    public function metric(string $apiKey, array $data): void
    {
        $this->root->metric($apiKey, $data);
    }

    public function metrics(string $apiKey, string $slug, array $points): void
    {
        $this->root->metrics($apiKey, $slug, $points);
    }
}

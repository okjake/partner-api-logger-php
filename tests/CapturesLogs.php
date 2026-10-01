<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;

/**
 * A logger wired to a capturing ingest double, a fake monotonic clock and a
 * collecting `onError` — the harness the scope, trail and middleware tests
 * share. Every logger has `flushOnShutdown: false`, so nothing posts through a
 * PHPUnit mock after its test has finished. A using class defines
 * `private const KEY` (traits cannot declare constants on PHP 8.1).
 */
trait CapturesLogs
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $captured = [];

    /** @var list<LoggerErrorEvent> */
    private array $reported = [];

    /** Fake monotonic clock, in milliseconds. */
    private float $now = 0.0;

    /** Runs inside the ingest double on every POST, when set. */
    private ?\Closure $onPost = null;

    /**
     * @param array<string, mixed> $options
     */
    private function logger(array $options = []): Logger
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) {
                $this->captured[] = ['method' => $method, 'url' => $url, 'options' => $options];
                if ($this->onPost !== null) {
                    ($this->onPost)($options);
                }

                return new Response(200, [], '{}');
            }
        );

        return new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: $client,
            timestampProvider: fn () => 1234567890000,
            options: array_merge([
                'flushOnShutdown' => false,
                'batchSize' => 0,
                'onError' => function (LoggerErrorEvent $event): void {
                    $this->reported[] = $event;
                },
                'clock' => fn (): float => $this->now,
            ], $options),
        );
    }

    /**
     * Every line posted so far, decoded, in posting order, each with the
     * labels and root fields of the POST that carried it.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        $lines = [];
        foreach ($this->captured as $request) {
            foreach ($request['options']['json']['entries'] as $entry) {
                $line = json_decode($entry['line'], true, 512, JSON_THROW_ON_ERROR);
                $line['__labels'] = $request['options']['json']['labels'];
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> The `Outgoing response` lines, in posting order. */
    private function responses(): array
    {
        return array_values(array_filter(
            $this->lines(),
            static fn (array $line): bool => $line['message'] === 'Outgoing response',
        ));
    }

    /** @return list<string> The raw `line` strings posted so far. */
    private function rawLines(): array
    {
        $raw = [];
        foreach ($this->captured as $request) {
            foreach ($request['options']['json']['entries'] as $entry) {
                $raw[] = $entry['line'];
            }
        }

        return $raw;
    }

    /** @return list<string|null> `name` of each call on a response line. */
    private function names(array $responseLine): array
    {
        return array_map(static fn (array $call) => $call['name'] ?? null, $responseLine['upstream'] ?? []);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed> A valid call named `$name`.
     */
    private function call(string $name, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            'method' => 'GET',
            'url' => "https://vendor.test/{$name}",
            'status' => 200,
            'durationMs' => 5,
        ], $extra);
    }

    /** One request/response exchange on `$logger`, recording `$calls` in between. */
    private function exchange(Logger $logger, string $correlationId, ?callable $between = null): void
    {
        $logger->logRequest(self::KEY, [
            'method' => 'GET',
            'path' => '/orders',
            'headers' => ['x-correlation-id' => $correlationId],
        ]);
        if ($between !== null) {
            $between($logger);
        }
        $logger->logResponse(self::KEY, [
            'statusCode' => 200,
            'duration' => 12,
            'correlationId' => $correlationId,
        ]);
    }

    /** @return list<string> The messages reported to `onError` so far. */
    private function reportedMessages(): array
    {
        return array_map(static fn (LoggerErrorEvent $e) => $e->message, $this->reported);
    }
}

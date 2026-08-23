<?php

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\LoggerException;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the buffered transport added in 2.0.0 (PAPI-3672).
 *
 * The shared fixtures in `packages/logger-spec` pin the bytes on the wire for
 * a single log call; they say nothing about *when* those bytes are sent, which
 * is the whole of this change. Everything here therefore drives a fake
 * transport and a fake clock: batching caps, grouping, retry/backoff, buffer
 * overflow, `onError` swallowing, flush/shutdown idempotency, and the
 * load-bearing promise that no log method ever throws.
 *
 * Every logger built here passes `flushOnShutdown: false` — the real drain is
 * a `register_shutdown_function`, and letting it fire would post a batch
 * through a PHPUnit mock long after the test that built it has finished.
 */
class BufferedTransportTest extends TestCase
{
    private const KEY = 'app-key';

    /** @var list<array{method: string, url: string, options: array}> */
    private array $captured = [];

    /** @var list<LoggerErrorEvent> */
    private array $reported = [];

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $this->reported = [];
        $this->sleeps = [];
    }

    // ---------------------------------------------------------------- buffering

    public function testLogCallsBufferAndSendNothingUntilFlush(): void
    {
        $logger = $this->logger();

        $logger->info(self::KEY, 'one');
        $logger->info(self::KEY, 'two');

        $this->assertSame([], $this->captured, 'no request may be made on the log path');
        $this->assertSame(2, $logger->stats()['buffered']);

        $logger->flush();

        $this->assertCount(1, $this->captured);
        $this->assertCount(2, $this->body(0)['entries']);
        $this->assertSame(0, $logger->stats()['buffered']);
        $this->assertSame(2, $logger->stats()['delivered']);
    }

    public function testBatchSizeTriggersAnAutomaticDrain(): void
    {
        $logger = $this->logger(['batchSize' => 2]);

        $logger->info(self::KEY, 'one');
        $this->assertCount(0, $this->captured);

        $logger->info(self::KEY, 'two');
        $this->assertCount(1, $this->captured, 'reaching batchSize drains');
        $this->assertCount(2, $this->body(0)['entries']);

        $logger->info(self::KEY, 'three');
        $this->assertCount(1, $this->captured, 'the buffer starts filling again');
    }

    public function testFlushOnAnEmptyBufferSendsNothing(): void
    {
        $logger = $this->logger();

        $logger->flush();
        $logger->shutdown();

        $this->assertSame([], $this->captured);
    }

    public function testLogRequestReturnsACorrelationIdWithoutSending(): void
    {
        $logger = $this->logger();

        $correlationId = $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => '/users']);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $correlationId,
        );
        $this->assertSame([], $this->captured, 'the correlation ID costs no round-trip');
        $this->assertSame(1, $logger->stats()['buffered']);
    }

    // ----------------------------------------------------------------- grouping

    public function testEntriesAreGroupedByLevel(): void
    {
        $logger = $this->logger();

        $logger->info(self::KEY, 'a');
        $logger->error(self::KEY, 'b');
        $logger->info(self::KEY, 'c');
        $logger->flush();

        $this->assertCount(2, $this->captured, 'levels are per-request labels, so they cannot share a POST');
        $this->assertSame(['level' => 'info'], $this->body(0)['labels']);
        $this->assertCount(2, $this->body(0)['entries']);
        $this->assertSame(['level' => 'error'], $this->body(1)['labels']);
        $this->assertCount(1, $this->body(1)['entries']);
    }

    public function testEntriesAreGroupedByApiKey(): void
    {
        $logger = $this->logger();

        $logger->info('key-a', 'a');
        $logger->info('key-b', 'b');
        $logger->flush();

        $this->assertCount(2, $this->captured);
        $this->assertSame('key-a', $this->captured[0]['options']['headers']['x-api-key']);
        $this->assertSame('key-b', $this->captured[1]['options']['headers']['x-api-key']);
    }

    public function testEntriesAreGroupedByPartnerAndUpstreamAttribution(): void
    {
        $logger = $this->logger();

        $logger->setContext(['partnerId' => 'p1', 'direction' => 'outbound', 'upstreamIntegration' => 'stripe']);
        $logger->info(self::KEY, 'a');
        $logger->setContext(['upstreamIntegration' => 'adyen']);
        $logger->info(self::KEY, 'b');
        $logger->flush();

        $this->assertCount(2, $this->captured, 'upstream attribution is a per-request field');

        $first = $this->body(0);
        $this->assertSame(['level' => 'info', 'partnerId' => 'p1', 'direction' => 'outbound'], $first['labels']);
        $this->assertSame('stripe', $first['upstream_integration']);
        $this->assertSame('adyen', $this->body(1)['upstream_integration']);
    }

    public function testLabelsAreSnapshottedAtEnqueueTime(): void
    {
        $logger = $this->logger();

        $logger->setContext(['partnerId' => 'first']);
        $logger->info(self::KEY, 'a');
        $logger->setContext(['partnerId' => 'second']);
        $logger->flush();

        $this->assertSame('first', $this->body(0)['labels']['partnerId'], 'a later setContext must not retro-label');
    }

    // ------------------------------------------------------------ batching caps

    public function testABatchIsCappedAtTheIngestThousandEntryMaximum(): void
    {
        $logger = $this->logger(['batchSize' => 0, 'maxBufferSize' => 2000]);

        for ($i = 0; $i < 1500; $i++) {
            $logger->info(self::KEY, "entry-{$i}");
        }
        $logger->flush();

        $this->assertCount(2, $this->captured);
        $this->assertCount(1000, $this->body(0)['entries']);
        $this->assertCount(500, $this->body(1)['entries']);
    }

    public function testABatchIsCappedByTheRequestLineByteBudget(): void
    {
        $logger = $this->logger(['batchSize' => 0]);

        // Three entries of ~400 KB: two fit under the 1 MB budget, the third
        // starts a new request rather than risking ingest's body limit.
        for ($i = 0; $i < 3; $i++) {
            $logger->info(self::KEY, 'big', ['blob' => str_repeat('x', 400000)]);
        }
        $logger->flush();

        $this->assertCount(2, $this->captured);
        $this->assertCount(2, $this->body(0)['entries']);
        $this->assertCount(1, $this->body(1)['entries']);
    }

    // ----------------------------------------------------------------- overflow

    public function testAFullBufferDropsOldestAndReports(): void
    {
        $logger = $this->logger(['batchSize' => 0, 'maxBufferSize' => 3]);

        foreach (['a', 'b', 'c', 'd', 'e'] as $message) {
            $logger->info(self::KEY, $message);
        }

        $this->assertCount(2, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_BUFFER_OVERFLOW, $this->reported[0]->reason);
        $this->assertSame('Log buffer full (3) — dropped 1 oldest entry', $this->reported[0]->message);
        $this->assertSame(1, $this->reported[0]->entryCount);
        $this->assertSame(2, $this->reported[1]->droppedTotal);

        $logger->flush();

        $lines = array_map(
            fn (array $entry) => json_decode($entry['line'], true)['message'],
            $this->body(0)['entries'],
        );
        $this->assertSame(['c', 'd', 'e'], $lines, 'the newest entries survive');
        $this->assertSame(2, $logger->stats()['dropped']);
    }

    // -------------------------------------------------------------- never throws

    public function testAMissingApiKeyIsReportedNotThrown(): void
    {
        $logger = $this->logger();

        $logger->info('', 'no key');
        $logger->flush();

        $this->assertSame([], $this->captured);
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
        $this->assertSame('API key is required for logging', $this->reported[0]->message);
        $this->assertSame(1, $logger->stats()['dropped']);
    }

    public function testUnserialisableDataIsReportedNotThrown(): void
    {
        $logger = $this->logger();

        // Invalid UTF-8 makes json_encode return false; before 2.0.0 that
        // shipped a literal `false` as the log line.
        $logger->info(self::KEY, 'bad', ['blob' => "\xB1\x31"]);
        $logger->flush();

        $this->assertSame([], $this->captured);
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
        $this->assertStringStartsWith(
            'Failed to send log: log entry could not be serialised: ',
            $this->reported[0]->message,
        );
    }

    public function testATransportFailureNeverReachesTheCaller(): void
    {
        $logger = $this->logger(
            ['maxRetries' => 0],
            [new \RuntimeException('Network timeout')],
        );

        $logger->info(self::KEY, 'a');
        $logger->flush();

        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_FLUSH_FAILED, $this->reported[0]->reason);
        $this->assertSame('Failed to send log: Network timeout', $this->reported[0]->message);
        $this->assertSame(1, $this->reported[0]->entryCount);
        $this->assertSame(1, $this->reported[0]->attempts);
        $this->assertNull($this->reported[0]->status);
        $this->assertTrue($this->reported[0]->retryable);
        $this->assertSame(1, $logger->stats()['dropped']);
        $this->assertSame(0, $logger->stats()['delivered']);
    }

    public function testAThrowingOnErrorHookIsSwallowed(): void
    {
        $logger = new Logger(
            tenantToken: 'tenant',
            httpClient: $this->transport([new \RuntimeException('down')]),
            timestampProvider: fn () => 1234567890000,
            options: [
                'maxRetries' => 0,
                'flushOnShutdown' => false,
                'onError' => function (): void {
                    throw new \RuntimeException('the hook is broken too');
                },
            ],
        );

        $logger->info(self::KEY, 'a');
        $logger->flush();

        $this->assertSame(1, $logger->stats()['dropped']);
    }

    // ------------------------------------------------------------------- retries

    public function testTransientFailuresAreRetriedWithEqualJitterBackoff(): void
    {
        $logger = $this->logger(
            ['maxRetries' => 3, 'retryBaseDelayMs' => 200, 'retryMaxDelayMs' => 5000],
            [new \RuntimeException('reset'), $this->serverError(503)],
        );

        $logger->info(self::KEY, 'a');
        $logger->flush();

        $this->assertCount(3, $this->captured, 'two failures, then a success');
        // randomizer is pinned to 0.5, so each delay is 0.75 * the doubling
        // ceiling: 200 -> 150, 400 -> 300.
        $this->assertSame([150, 300], $this->sleeps);
        $this->assertSame([], $this->reported);
        $this->assertSame(1, $logger->stats()['delivered']);
    }

    public function testBackoffIsClampedToTheConfiguredCeiling(): void
    {
        $logger = $this->logger(
            ['maxRetries' => 3, 'retryBaseDelayMs' => 200, 'retryMaxDelayMs' => 300],
            [$this->serverError(500), $this->serverError(500), $this->serverError(500)],
        );

        $logger->info(self::KEY, 'a');
        $logger->flush();

        $this->assertSame([150, 225, 225], $this->sleeps);
    }

    public function testANonRetryableStatusIsDroppedImmediately(): void
    {
        $logger = $this->logger(
            ['maxRetries' => 3],
            [$this->clientError(400)],
        );

        $logger->info(self::KEY, 'a');
        $logger->flush();

        $this->assertCount(1, $this->captured, 'a 400 will be a 400 next time too');
        $this->assertSame([], $this->sleeps);
        $this->assertCount(1, $this->reported);
        $this->assertSame(400, $this->reported[0]->status);
        $this->assertFalse($this->reported[0]->retryable);
        $this->assertStringStartsWith('Failed to send log: ', $this->reported[0]->message);
    }

    public function testRetriesAreBoundedAndTheBatchIsThenDropped(): void
    {
        $logger = $this->logger(
            ['maxRetries' => 2],
            [$this->serverError(500), $this->serverError(500), $this->serverError(500)],
        );

        $logger->info(self::KEY, 'a');
        $logger->info(self::KEY, 'b');
        $logger->flush();

        $this->assertCount(3, $this->captured, 'first attempt plus two retries');
        $this->assertCount(1, $this->reported);
        $this->assertSame(3, $this->reported[0]->attempts);
        $this->assertSame(2, $this->reported[0]->entryCount);
        $this->assertSame(500, $this->reported[0]->status);
        $this->assertTrue($this->reported[0]->retryable);
        $this->assertSame(2, $logger->stats()['dropped']);
    }

    // ------------------------------------------------------ flush / shutdown / stats

    public function testFlushAndShutdownAreIdempotent(): void
    {
        $logger = $this->logger();

        $logger->info(self::KEY, 'a');
        $logger->flush();
        $logger->flush();
        $logger->shutdown();
        $logger->shutdown();
        $logger->close();

        $this->assertCount(1, $this->captured, 'entries are never posted twice');
    }

    public function testTheLoggerStaysUsableAfterShutdown(): void
    {
        $logger = $this->logger();

        $logger->info(self::KEY, 'a');
        $logger->shutdown();
        $logger->info(self::KEY, 'b');
        $logger->flush();

        $this->assertCount(2, $this->captured);
    }

    public function testStatsTrackBufferedDeliveredAndDropped(): void
    {
        $logger = $this->logger(
            ['maxRetries' => 0],
            [null, new \RuntimeException('down')],
        );

        $this->assertSame(['buffered' => 0, 'delivered' => 0, 'dropped' => 0], $logger->stats());

        $logger->info(self::KEY, 'delivered');
        $logger->flush();
        $this->assertSame(['buffered' => 0, 'delivered' => 1, 'dropped' => 0], $logger->stats());

        $logger->info(self::KEY, 'lost');
        $logger->flush();
        $this->assertSame(['buffered' => 0, 'delivered' => 1, 'dropped' => 1], $logger->stats());

        $logger->info(self::KEY, 'waiting');
        $this->assertSame(['buffered' => 1, 'delivered' => 1, 'dropped' => 1], $logger->stats());
    }

    // --------------------------------------------------------------- direct mode

    public function testDirectModePostsSynchronously(): void
    {
        $logger = $this->logger(['mode' => Logger::MODE_DIRECT]);

        $logger->info(self::KEY, 'a');

        $this->assertCount(1, $this->captured, 'direct mode keeps the pre-2.0 round-trip');
        $this->assertSame(0, $logger->stats()['buffered']);
    }

    public function testDirectModeStillThrowsOnFailure(): void
    {
        $logger = $this->logger(['mode' => Logger::MODE_DIRECT], [new \RuntimeException('Network timeout')]);

        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('Failed to send log: Network timeout');

        $logger->info(self::KEY, 'a');
    }

    public function testDirectModeStillThrowsOnAMissingApiKey(): void
    {
        $logger = $this->logger(['mode' => Logger::MODE_DIRECT]);

        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('API key is required for logging');

        $logger->info('', 'a');
    }

    public function testAnUnknownConstructorOptionIsRejected(): void
    {
        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('Unknown Logger option(s): maxRetryes');

        new Logger(tenantToken: 'tenant', options: ['maxRetryes' => 1]);
    }

    // ------------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $options
     * @param list<\Throwable|null> $outcomes Per-request outcome; null (or a
     *        short list) means a 200.
     */
    private function logger(array $options = [], array $outcomes = []): Logger
    {
        return new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: $this->transport($outcomes),
            timestampProvider: fn () => 1234567890000,
            options: array_merge([
                'flushOnShutdown' => false,
                'onError' => function (LoggerErrorEvent $event): void {
                    $this->reported[] = $event;
                },
                'sleeper' => function (int $milliseconds): void {
                    $this->sleeps[] = $milliseconds;
                },
                // Pinned so the equal-jitter window is assertable.
                'randomizer' => static fn (): float => 0.5,
            ], $options),
        );
    }

    /**
     * @param list<\Throwable|null> $outcomes
     */
    private function transport(array $outcomes): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$outcomes) {
                $this->captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

                $outcome = array_shift($outcomes);
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return new Response(200, [], '{}');
            }
        );

        return $client;
    }

    private function clientError(int $status): ClientException
    {
        return new ClientException(
            "Client error: `POST /logs` resulted in a `{$status}` response",
            new Request('POST', 'https://ingest.test/logs'),
            new Response($status, [], '{}'),
        );
    }

    private function serverError(int $status): ServerException
    {
        return new ServerException(
            "Server error: `POST /logs` resulted in a `{$status}` response",
            new Request('POST', 'https://ingest.test/logs'),
            new Response($status, [], '{}'),
        );
    }

    /**
     * @return array<string, mixed> The JSON body of the nth captured request.
     */
    private function body(int $index): array
    {
        return $this->captured[$index]['options']['json'];
    }
}

<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerException;
use PartnerApi\Logger\UpstreamTrail;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * `Logger::upstreamMiddleware()` — the Guzzle counterpart of the TypeScript
 * SDK's `wrapFetch` (PAPI-5337 parity, FLT-1301).
 *
 * Everything runs a REAL `GuzzleHttp\Client` over a `MockHandler`, so what is
 * under test is the middleware in a real stack — its position relative to
 * `http_errors` and `retry` included — not our idea of Guzzle.
 */
class UpstreamMiddlewareTest extends TestCase
{
    use CapturesLogs;

    private const KEY = 'app-key';

    protected function setUp(): void
    {
        $this->captured = [];
        $this->reported = [];
        $this->now = 0.0;
    }

    public function testRecordsMethodUrlStatusDurationAndTheVendorRequestId(): void
    {
        $logger = $this->logger();
        $client = $this->vendor($logger, [
            function (RequestInterface $request) {
                $this->now += 37; // the call takes 37 ms on the logger's clock

                return new Response(200, ['Request-Id' => 'req_8Hk2Lx9'], '{"id":"ch_1"}');
            },
        ]);

        $this->exchange($logger, 'corr-1', function () use ($client): void {
            $response = $client->request('post', 'https://api.stripe.com/v1/charges?expand[]=customer', ['body' => 'amount=100']);
            $this->assertSame('{"id":"ch_1"}', (string) $response->getBody(), 'the caller gets its response untouched');
        });
        $logger->flush();

        $this->assertSame([[
            'name' => 'stripe',
            'method' => 'POST',
            'url' => 'https://api.stripe.com/v1/charges',
            'status' => 200,
            'durationMs' => 37,
            'requestId' => 'req_8Hk2Lx9',
        ]], $this->responses()[0]['upstream']);
        $this->assertSame([], $this->reported);
    }

    public function testChecksRequestIdHeadersBeforeTheDefaultsAndOmitsRequestIdWhenNoneIsPresent(): void
    {
        $logger = $this->logger();
        $both = ['X-Request-Id' => 'from-default-list', 'cf-ray' => 'ray-1', 'X-Vendor-Trace' => 'vendor-own'];
        $defaultsOnly = $this->vendor($logger, [new Response(200, $both), new Response(200, ['cf-ray' => 'ray-2']), new Response(204)]);
        $custom = $this->vendor($logger, [new Response(200, $both)], 'pms', ['requestIdHeaders' => ['x-vendor-trace']]);

        $this->exchange($logger, 'corr-1', function () use ($defaultsOnly, $custom): void {
            $defaultsOnly->get('https://vendor.test/a');
            $defaultsOnly->get('https://vendor.test/b');
            $defaultsOnly->get('https://vendor.test/c');
            $custom->get('https://pms.test/d');
        });
        $logger->flush();

        $trail = $this->responses()[0]['upstream'];
        $this->assertSame('from-default-list', $trail[0]['requestId'], 'x-request-id outranks cf-ray, as in TS');
        $this->assertSame('ray-2', $trail[1]['requestId']);
        $this->assertArrayNotHasKey('requestId', $trail[2]);
        $this->assertSame('vendor-own', $trail[3]['requestId'], 'requestIdHeaders are checked first');
        $this->assertSame(UpstreamTrail::DEFAULT_REQUEST_ID_HEADERS, [
            'request-id', 'x-request-id', 'x-amzn-requestid', 'x-amz-request-id', 'x-ms-request-id', 'x-github-request-id', 'cf-ray',
        ], 'the TypeScript SDK\'s DEFAULT_UPSTREAM_REQUEST_ID_HEADERS, in order');
    }

    public function testATransportErrorIsRecordedWithNoStatusAndRethrownUnchanged(): void
    {
        $logger = $this->logger();
        $refused = new ConnectException(
            'cURL error 7: Failed to connect to vendor.test port 443: Connection refused for https://vendor.test/v1/x?token=sk_live_9',
            new \GuzzleHttp\Psr7\Request('GET', 'https://vendor.test/v1/x?token=sk_live_9'),
            null,
            ['errno' => 7],
        );
        $odd = new TransferException('something else broke');
        $client = $this->vendor($logger, [$refused, $odd]);

        $this->exchange($logger, 'corr-1', function () use ($client, $refused, $odd): void {
            foreach ([$refused, $odd] as $expected) {
                try {
                    $client->get('https://vendor.test/v1/x?token=sk_live_9');
                    $this->fail('the transport error must reach the caller');
                } catch (\Throwable $caught) {
                    $this->assertSame($expected, $caught, 'the SAME exception, unchanged');
                }
            }
        });
        $logger->flush();

        [$connect, $other] = $this->responses()[0]['upstream'];
        $this->assertSame([
            'name' => 'stripe',
            'method' => 'GET',
            'url' => 'https://vendor.test/v1/x',
            'durationMs' => 0,
            'errorCode' => 'CURLE_COULDNT_CONNECT',
            'message' => 'cURL error 7: Failed to connect to vendor.test port 443: Connection refused for https://vendor.test/v1/x',
        ], $connect, 'no status for a network error; the query string is not smuggled out in message');
        $this->assertSame('TransferException', $other['errorCode']);
        $this->assertSame('something else broke', $other['message']);
        $this->assertStringNotContainsString('sk_live_9', implode('', $this->rawLines()));
    }

    public function testRecordsTheStatusOf4xxAnd5xxWithHttpErrorsOnAndOff(): void
    {
        foreach ([true, false] as $httpErrors) {
            $this->captured = [];
            $logger = $this->logger();
            $client = $this->vendor($logger, [new Response(402, ['request-id' => 'req_402']), new Response(503)]);

            $this->exchange($logger, 'corr', function () use ($client, $httpErrors): void {
                foreach (['ClientException' => '/charges', 'ServerException' => '/refunds'] as $class => $path) {
                    try {
                        $response = $client->post("https://api.stripe.com/v1{$path}", ['http_errors' => $httpErrors]);
                        $this->assertFalse($httpErrors, 'only with http_errors off does the response come back');
                        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
                    } catch (ClientException|ServerException $e) {
                        $this->assertTrue($httpErrors);
                        $this->assertStringEndsWith($class, get_class($e));
                    }
                }
            });
            $logger->flush();

            $trail = $this->responses()[0]['upstream'];
            $label = $httpErrors ? 'http_errors on' : 'http_errors off';
            $this->assertSame([402, 503], array_column($trail, 'status'), $label);
            $this->assertSame('req_402', $trail[0]['requestId'], $label);
            $this->assertArrayNotHasKey('errorCode', $trail[0], "{$label}: an HTTP error is not a network error");
        }
    }

    public function testRecordsTheStatusWhenOutsideHttpErrorsTooAndRethrowsTheSameException(): void
    {
        // Pushed OUTSIDE `http_errors` (unshift): the 404 arrives as a
        // rejected RequestException carrying the response.
        $logger = $this->logger();
        $stack = HandlerStack::create(new MockHandler([new Response(404, ['x-request-id' => 'req_404'])]));
        $stack->unshift($logger->upstreamMiddleware('pms'));
        $client = new Client(['handler' => $stack]);
        $caught = null;

        $this->exchange($logger, 'corr', function () use ($client, &$caught): void {
            try {
                $client->get('https://pms.test/bookings/1');
            } catch (ClientException $e) {
                $caught = $e;
            }
        });
        $logger->flush();

        $this->assertNotNull($caught, 'http_errors still raises for the caller');
        $this->assertSame(404, $caught->getResponse()->getStatusCode());
        $call = $this->responses()[0]['upstream'][0];
        $this->assertSame(404, $call['status']);
        $this->assertSame('req_404', $call['requestId']);
        $this->assertArrayNotHasKey('errorCode', $call);
    }

    public function testNeverReadsTheResponseBody(): void
    {
        $logger = $this->logger();
        $body = FnStream::decorate(Utils::streamFor('secret body'), [
            'read' => function () {
                throw new \LogicException('the middleware read the body');
            },
            'getContents' => function () {
                throw new \LogicException('the middleware read the body');
            },
            '__toString' => function () {
                throw new \LogicException('the middleware read the body');
            },
        ]);
        $client = $this->vendor($logger, [new Response(200, [], $body)]);

        $this->exchange($logger, 'corr', fn () => $client->get('https://vendor.test/stream', ['stream' => true]));
        $logger->flush();

        $this->assertSame(200, $this->responses()[0]['upstream'][0]['status']);
        $this->assertSame([], $this->reported);
    }

    public function testRecordsTheAttemptWhenInsideGuzzlesRetryMiddleware(): void
    {
        $logger = $this->logger();
        $retryTwice = Middleware::retry(
            static fn (int $retries, $request, $response) => $retries < 2 && $response?->getStatusCode() === 503,
            static fn () => 0,
        );

        // Inside retry: a HandlerStack nests in push order — the first
        // pushed is the OUTERMOST — so retry goes on first and this
        // middleware after it. One record per attempt, numbered by retry's
        // own counter.
        $inside = HandlerStack::create(new MockHandler([new Response(503), new Response(503), new Response(200)]));
        $inside->push($retryTwice);
        $inside->push($logger->upstreamMiddleware('inside'));

        // Outside retry: one record for the whole retried call, no attempt.
        $outside = HandlerStack::create(new MockHandler([new Response(503), new Response(200)]));
        $outside->push($logger->upstreamMiddleware('outside'));
        $outside->push($retryTwice);

        $this->exchange($logger, 'corr', function () use ($inside, $outside): void {
            (new Client(['handler' => $inside]))->get('https://vendor.test/flaky');
            (new Client(['handler' => $outside]))->get('https://vendor.test/flaky');
        });
        $logger->flush();

        $trail = $this->responses()[0]['upstream'];
        $this->assertSame(
            [['inside', 503, 0], ['inside', 503, 1], ['inside', 200, 2], ['outside', 200, null]],
            array_map(fn ($c) => [$c['name'], $c['status'], $c['attempt'] ?? null], $trail),
        );
    }

    public function testAttributesAsyncCallsToTheScopeTheyWereSentFrom(): void
    {
        $logger = $this->logger();
        // ONE client, built at boot, serving two requests.
        $client = $this->vendor($logger, [new Response(200), new Response(201)]);
        $promises = [];

        foreach (['a', 'b'] as $request) {
            $logger->runWithContext([], function () use ($logger, $client, $request, &$promises): void {
                $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => "/{$request}", 'headers' => ['x-correlation-id' => $request]]);
                $promises[$request] = $client->getAsync("https://vendor.test/{$request}");
                // Settle only once both are in flight, outside both scopes.
                if ($request === 'b') {
                    $promises['a']->wait();
                    $promises['b']->wait();
                }
                $logger->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 1, 'correlationId' => $request]);
            });
        }
        $logger->flush();

        // a's call settled after a's scope ended with no response holding it
        // — it is reported, never put on b's line.
        [$a, $b] = $this->responses();
        $this->assertArrayNotHasKey('upstream', $a);
        $this->assertSame(['https://vendor.test/b'], array_column($b['upstream'], 'url'));
        $this->assertSame(1, $logger->stats()['upstreamDropped']);
    }

    public function testOrdersOverlappingCallsByWhenTheyStarted(): void
    {
        $logger = $this->logger();
        $client = $this->vendor($logger, [new Response(200), new Response(200)]);

        $this->exchange($logger, 'corr', function () use ($client): void {
            $first = $client->getAsync('https://vendor.test/started-first');
            $second = $client->getAsync('https://vendor.test/started-second');
            $second->wait();
            $first->wait();
        });
        $logger->flush();

        $this->assertSame(
            ['https://vendor.test/started-first', 'https://vendor.test/started-second'],
            array_column($this->responses()[0]['upstream'], 'url'),
        );
    }

    public function testAMiddlewareMadeFromAChildAlwaysRecordsIntoThatChild(): void
    {
        $logger = $this->logger();
        $child = $logger->child(['partnerId' => 'child']);
        $client = $this->vendor($child, [new Response(200), new Response(200)]);

        // Sent from outside any scope, and from inside an unrelated one.
        $client->get('https://vendor.test/from-root');
        $logger->runWithContext([], fn () => $client->get('https://vendor.test/from-elsewhere'));
        $this->exchange($child, 'child-corr');
        $logger->flush();

        $this->assertSame(
            ['https://vendor.test/from-root', 'https://vendor.test/from-elsewhere'],
            array_column($this->responses()[0]['upstream'], 'url'),
        );
        $this->assertSame(0, $logger->stats()['upstreamDropped']);
    }

    public function testNeverRecordsTheLoggersOwnPostsToIngest(): void
    {
        // A client carrying the middleware, also used as the logger's own
        // transport: the logger's POSTs must never land in its trail.
        $history = [];
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 8, new Response(200))));
        $stack->push(Middleware::history($history));
        $logger = new Logger('tenant-token', 'https://ingest.test', new Client(['handler' => $stack]), fn () => 1234567890000, [
            'flushOnShutdown' => false,
            'batchSize' => 1, // every log call posts at once
            'onError' => function ($event): void {
                $this->reported[] = $event;
            },
        ]);
        $stack->push($logger->upstreamMiddleware('self'));

        $logger->info(self::KEY, 'posted before the response');
        $this->exchange($logger, 'first');
        $this->exchange($logger, 'second');
        $logger->flush();

        $lines = [];
        foreach ($history as $transaction) {
            foreach (json_decode((string) $transaction['request']->getBody(), true)['entries'] as $entry) {
                $lines[] = json_decode($entry['line'], true);
            }
        }
        $this->assertCount(5, $lines, 'every line went out through the wrapped client');
        foreach ($lines as $line) {
            $this->assertArrayNotHasKey('upstream', $line, "{$line['message']}: an ingest POST was recorded");
        }
        $this->assertSame([], $this->reported);
    }

    public function testARecordingFailureGoesToOnErrorAndNeverIntoTheRequest(): void
    {
        $ticks = 0;
        $logger = $this->logger(['clock' => function () use (&$ticks): float {
            // Fine when the call starts, broken when it is recorded.
            if ($ticks++ > 0) {
                throw new \RuntimeException('clock broke mid-call');
            }

            return 0.0;
        }]);
        $client = $this->vendor($logger, [new Response(200, [], 'ok')]);

        $response = $client->get('https://vendor.test/x');

        $this->assertSame('ok', (string) $response->getBody());
        $this->assertCount(1, $this->reported);
        $this->assertSame('logger.upstream(): call could not be recorded: clock broke mid-call', $this->reported[0]->message);
    }

    public function testAnUnknownOptionFailsAtSetupNeverPerCall(): void
    {
        $logger = $this->logger();

        try {
            $logger->upstreamMiddleware('x', ['requestIdHeader' => ['typo']]);
            $this->fail('an unknown option must raise');
        } catch (LoggerException $e) {
            $this->assertStringContainsString('requestIdHeader', $e->getMessage());
        }

        $this->expectException(LoggerException::class);
        $logger->upstreamMiddleware('x', ['requestIdHeaders' => [42]]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A real Guzzle client over a MockHandler, with the middleware pushed the
     * documented way: onto `HandlerStack::create()`.
     *
     * @param list<mixed> $queue
     * @param array<string, mixed> $options
     */
    private function vendor(Logger $logger, array $queue, string $name = 'stripe', array $options = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push($logger->upstreamMiddleware($name, $options));

        return new Client(['handler' => $stack]);
    }
}

<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\UpstreamTrail;
use PHPUnit\Framework\TestCase;

/**
 * The upstream call trail (PAPI-5337) — PHP parity, FLT-1301.
 *
 * Contract: `packages/logger-spec/spec.md` § "Upstream call trail". The
 * TypeScript reference is `packages/logger/src/upstream-trail.spec.ts`; the
 * cases here follow it, minus the ones PHP's synchronous model makes moot
 * (a `runWithContext()` that returns synchronously is ENDED here — there is
 * no AsyncLocalStorage continuation to keep it alive).
 */
class UpstreamTrailTest extends TestCase
{
    use CapturesLogs;

    private const KEY = 'app-key';

    protected function setUp(): void
    {
        $this->captured = [];
        $this->reported = [];
        $this->now = 0.0;
    }

    // ----------------------------------------------------------------- placement

    public function testShipsCallsOnTheResponseLineOnlyInOrderWithTheSpecShape(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', function (Logger $logger): void {
            $logger->upstream([
                // Keys in a scrambled order, plus one ingest does not know.
                'attempt' => 2,
                'durationMs' => 41.5,
                'url' => 'https://pricing.internal/quote',
                'message' => 'pricing service unavailable',
                'name' => 'pricing',
                'errorCode' => 'upstream_unavailable',
                'status' => 503,
                'requestId' => 'req_8Hk2Lx9',
                'method' => 'M-SEARCH',
                'tenantSecret' => 'never shipped',
            ]);
            $logger->info(self::KEY, 'between');
            $logger->upstream($this->call('second'));
        });
        $logger->flush();

        $lines = $this->lines();
        $this->assertSame(['Incoming request', 'between', 'Outgoing response'], array_column($lines, 'message'));
        $this->assertArrayNotHasKey('upstream', $lines[0], 'a request line never carries a trail');
        $this->assertArrayNotHasKey('upstream', $lines[1]);

        $trail = $lines[2]['upstream'];
        $this->assertSame(['pricing', 'second'], array_column($trail, 'name'), 'call order, oldest first');
        $this->assertSame(
            ['name', 'method', 'url', 'status', 'durationMs', 'requestId', 'errorCode', 'message', 'attempt'],
            array_keys($trail[0]),
            'exactly the spec\'s fields, in the spec\'s order',
        );
        $this->assertSame([
            'name' => 'pricing',
            'method' => 'M-SEARCH',
            'url' => 'https://pricing.internal/quote',
            'status' => 503,
            'durationMs' => 41.5,
            'requestId' => 'req_8Hk2Lx9',
            'errorCode' => 'upstream_unavailable',
            'message' => 'pricing service unavailable',
            'attempt' => 2,
        ], $trail[0]);
        $this->assertArrayNotHasKey('_upstreamTruncated', $lines[2]);
        $this->assertArrayNotHasKey('_upstreamDropped', $lines[2]);
    }

    public function testTheTrailFollowsTheResponseFieldsOnTheLine(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', fn (Logger $l) => $l->upstream($this->call('a')));
        $logger->flush();

        // The response fields, then the trail — the TypeScript SDK's order.
        $keys = array_keys($this->responses()[0]);
        $this->assertSame(
            ['level', 'message', 'path', 'method', 'status_code', 'duration_ms', 'correlation_id', 'headers', 'body', 'upstream'],
            array_slice($keys, 0, -1),
        );
    }

    public function testAResponseWithNoCallsIsByteIdenticalToOneWithoutTheFeature(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1');
        $logger->flush();

        $raw = $this->rawLines()[1];
        $this->assertSame($this->expectedPlainResponse(), $raw);
        $this->assertStringNotContainsString('upstream', $raw, 'no `upstream` key at all — never []');
    }

    public function testClearsTheTrailAfterEachResponseAndKeepsCollecting(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'corr-1', fn (Logger $l) => $l->upstream($this->call('first')));
            $this->exchange($logger, 'corr-2');
            $this->exchange($logger, 'corr-3', fn (Logger $l) => $l->upstream($this->call('third')));
        });
        $logger->flush();

        [$one, $two, $three] = $this->responses();
        $this->assertSame(['first'], $this->names($one));
        $this->assertArrayNotHasKey('upstream', $two);
        $this->assertSame(['third'], $this->names($three));
    }

    public function testTheLoggerWideTrailClearsAfterEachResponse(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', fn (Logger $l) => $l->upstream($this->call('first')));
        $this->exchange($logger, 'corr-2');
        $logger->flush();

        [$one, $two] = $this->responses();
        $this->assertSame(['first'], $this->names($one));
        $this->assertArrayNotHasKey('upstream', $two);
    }

    public function testTheTrailIsClearedEvenWhenTheResponseLineIsLost(): void
    {
        $logger = $this->logger();
        $logger->upstream($this->call('lost-with-its-line'));
        // A missing API key loses the line; the exchange still answered.
        $logger->logResponse('', ['statusCode' => 500, 'duration' => 1, 'correlationId' => 'c']);
        $this->exchange($logger, 'corr-2');
        $logger->flush();

        $this->assertCount(1, $this->responses());
        $this->assertArrayNotHasKey('upstream', $this->responses()[0]);
    }

    // ---------------------------------------------------------- per request

    public function testEachScopeShipsOnlyItsOwnCalls(): void
    {
        $logger = $this->logger();
        $a = $logger->child(['partnerId' => 'a']);
        $b = $logger->child(['partnerId' => 'b']);

        $a->logRequest(self::KEY, ['method' => 'GET', 'path' => '/a', 'headers' => ['x-correlation-id' => 'corr-a']]);
        $b->logRequest(self::KEY, ['method' => 'GET', 'path' => '/b', 'headers' => ['x-correlation-id' => 'corr-b']]);
        $a->upstream($this->call('a-1'));
        $b->upstream($this->call('b-1'));
        $a->upstream($this->call('a-2'));
        $b->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 1, 'correlationId' => 'corr-b']);
        $a->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 1, 'correlationId' => 'corr-a']);
        $logger->flush();

        $byCorrelation = [];
        foreach ($this->responses() as $line) {
            $byCorrelation[$line['correlation_id']] = $this->names($line);
        }
        $this->assertSame(['corr-a' => ['a-1', 'a-2'], 'corr-b' => ['b-1']], $byCorrelation);
        $this->assertSame([], $this->reported);
    }

    public function testAScopedResponseNeverPicksUpLoggerWideCallsAndViceVersa(): void
    {
        $logger = $this->logger();
        $logger->upstream($this->call('logger-wide'));
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'scoped', fn (Logger $l) => $l->upstream($this->call('scoped-call')));
        });
        $this->exchange($logger, 'unscoped');
        $logger->flush();

        [$scoped, $unscoped] = $this->responses();
        $this->assertSame(['scoped-call'], $this->names($scoped));
        $this->assertSame(['logger-wide'], $this->names($unscoped));
    }

    public function testPreFlightCallsShipOnTheRequestsOwnResponse(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $logger->upstream($this->call('auth-lookup')); // before logRequest
            $this->exchange($logger, 'corr-1', fn (Logger $l) => $l->upstream($this->call('in-exchange')));
        });
        $logger->flush();

        $this->assertSame(['auth-lookup', 'in-exchange'], $this->names($this->responses()[0]));
    }

    // ---------------------------------------------------------- nested scopes

    public function testANestedScopeHandsItsCallsToTheRequestWhenFnReturns(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'corr-1', function (Logger $l) use ($logger): void {
                $l->upstream($this->call('outer-1'));
                $logger->runWithContext(['partnerId' => 'nested'], function () use ($logger): void {
                    $logger->upstream($this->call('nested-1'));
                    $logger->upstream($this->call('nested-2'));
                });
                $l->upstream($this->call('outer-2'));
            });
        });
        $logger->flush();

        $this->assertSame(['outer-1', 'nested-1', 'nested-2', 'outer-2'], $this->names($this->responses()[0]));
        $this->assertSame(0, $logger->stats()['upstreamDropped']);
    }

    public function testANestedScopeHandsOffWhenFnThrowsToo(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'corr-1', function () use ($logger): void {
                try {
                    $logger->runWithContext([], function () use ($logger): void {
                        $logger->upstream($this->call('before-throw'));
                        throw new \RuntimeException('nested handler failed');
                    });
                } catch (\RuntimeException) {
                }
            });
        });
        $logger->flush();

        $this->assertSame(['before-throw'], $this->names($this->responses()[0]));
    }

    public function testANestedScopedLoggerUsedAfterItsFnReturnedStillReachesTheRequest(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'corr-1', function () use ($logger): void {
                $kept = $logger->runWithContext([], fn (Logger $scoped) => $scoped);
                $kept->upstream($this->call('after-nested-ended'));
            });
        });
        $logger->flush();

        $this->assertSame(['after-nested-ended'], $this->names($this->responses()[0]));
    }

    public function testAChildOfARequestIsPulledOntoTheRequestsLineButKeepsItsOwnContext(): void
    {
        $logger = $this->logger();
        $request = $logger->child(['partnerId' => 'request']);
        $request->logRequest(self::KEY, ['method' => 'GET', 'path' => '/x', 'headers' => ['x-correlation-id' => 'corr-r']]);
        $helper = $request->child(['partnerId' => 'helper']);
        $helper->upstream($this->call('helper-call'));
        $helper->info(self::KEY, 'helper line');
        $request->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 1, 'correlationId' => 'corr-r']);
        $logger->flush();

        $this->assertSame(['helper-call'], $this->names($this->responses()[0]));
        $helperLine = array_values(array_filter($this->lines(), fn ($l) => $l['message'] === 'helper line'))[0];
        $this->assertSame('helper', $helperLine['partnerId'], 'only calls move, never context');
    }

    public function testANestedScopeThatLogsItsOwnResponseShipsItsCallsThereNotOnTheRequest(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'outer', function (Logger $l) use ($logger): void {
                $logger->runWithContext([], function () use ($logger): void {
                    $logger->upstream($this->call('nested-pre-flight'));
                    $this->exchange($logger, 'nested', fn (Logger $l) => $l->upstream($this->call('nested-own')));
                });
                $l->upstream($this->call('outer-own'));
            });
        });
        $logger->flush();

        $byCorrelation = [];
        foreach ($this->responses() as $line) {
            $byCorrelation[$line['correlation_id']] = $this->names($line);
        }
        $this->assertSame([
            'nested' => ['nested-pre-flight', 'nested-own'],
            'outer' => ['outer-own'],
        ], $byCorrelation);
    }

    public function testAScopeOpenedBetweenExchangesIsTopLevel(): void
    {
        $logger = $this->logger();
        $logger->runWithContext(['service' => 'app'], function () use ($logger): void {
            // The app scope answers a health check, then serves a request in
            // a nested scope: that scope is NOT the health check's.
            $this->exchange($logger, 'health');
            $logger->runWithContext([], function () use ($logger): void {
                $this->exchange($logger, 'request', fn (Logger $l) => $l->upstream($this->call('stripe')));
            });
            $this->exchange($logger, 'health-2');
        });
        $logger->flush();

        $byCorrelation = [];
        foreach ($this->responses() as $line) {
            $byCorrelation[$line['correlation_id']] = $this->names($line);
        }
        $this->assertSame(['health' => [], 'request' => ['stripe'], 'health-2' => []], $byCorrelation);
    }

    public function testANestedScopesDropsCountTowardTheRequestsMarkers(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $this->exchange($logger, 'corr-1', function (Logger $l) use ($logger): void {
                $l->upstream($this->call('outer'));
                $logger->runWithContext([], function () use ($logger): void {
                    for ($i = 0; $i < 22; $i++) {
                        $logger->child()->upstream($this->call("nested-{$i}"));
                    }
                });
            });
        });
        $logger->flush();

        $line = $this->responses()[0];
        $this->assertCount(20, $line['upstream']);
        $this->assertSame(true, $line['_upstreamTruncated']);
        $this->assertSame(3, $line['_upstreamDropped'], 'outer + nested-0 + nested-1, oldest first');
        $this->assertSame('nested-2', $line['upstream'][0]['name']);
    }

    public function testBoundsTheChildrenWaitingOnOneLongExchangeWithoutChangingTheLine(): void
    {
        $logger = $this->logger();
        $request = $logger->child();
        $request->logRequest(self::KEY, ['method' => 'GET', 'path' => '/boot', 'headers' => ['x-correlation-id' => 'long']]);
        for ($i = 0; $i < 1005; $i++) {
            $request->child()->upstream($this->call("child-{$i}"));
        }

        $scope = (new \ReflectionProperty(\PartnerApi\Logger\ScopedLogger::class, 'scope'))->getValue($request);
        $this->assertCount(1000, $scope->helpers, 'at most 1000 scopes wait');

        $request->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 1, 'correlationId' => 'long']);
        $logger->flush();
        $line = $this->responses()[0];
        $this->assertSame(array_map(fn ($i) => "child-{$i}", range(985, 1004)), $this->names($line));
        $this->assertSame(985, $line['_upstreamDropped']);
        $this->assertSame([], $scope->helpers);
    }

    // ------------------------------------------------------------------- caps

    public function testKeepsTheNewest20CallsAndMarksTheLine(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', function (Logger $l): void {
            for ($i = 0; $i < 23; $i++) {
                $l->upstream($this->call("call-{$i}"));
            }
        });
        $logger->flush();

        $line = $this->responses()[0];
        $this->assertSame(array_map(fn ($i) => "call-{$i}", range(3, 22)), $this->names($line));
        $this->assertTrue($line['_upstreamTruncated']);
        $this->assertSame(3, $line['_upstreamDropped']);
        $keys = array_keys($line);
        $this->assertSame(['upstream', '_upstreamTruncated', '_upstreamDropped'], array_slice($keys, array_search('upstream', $keys, true), 3));
    }

    public function testExactly20CallsIsNotTruncated(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', function (Logger $l): void {
            for ($i = 0; $i < UpstreamTrail::MAX_CALLS; $i++) {
                $l->upstream($this->call("call-{$i}"));
            }
        });
        $logger->flush();

        $line = $this->responses()[0];
        $this->assertCount(20, $line['upstream']);
        $this->assertArrayNotHasKey('_upstreamTruncated', $line);
        $this->assertArrayNotHasKey('_upstreamDropped', $line);
    }

    public function testDropsTheOldestCallsUntilTheTrailFits8192Bytes(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', function (Logger $l): void {
            for ($i = 0; $i < 12; $i++) {
                $l->upstream($this->call("call-{$i}", ['status' => 500, 'message' => str_repeat('x', 1000)]));
            }
        });
        $logger->flush();

        $line = $this->responses()[0];
        $raw = $this->rawLines()[1];
        $kept = count($line['upstream']);
        $this->assertLessThan(12, $kept);
        $this->assertSame(12 - $kept, $line['_upstreamDropped']);
        $this->assertSame('call-' . (12 - $kept), $line['upstream'][0]['name'], 'the oldest went first');
        // Measured on the bytes the trail occupies in the shipped line.
        $start = strpos($raw, '"upstream":') + strlen('"upstream":');
        $end = strpos($raw, ',"_upstreamTruncated"');
        $this->assertLessThanOrEqual(UpstreamTrail::MAX_BYTES, $end - $start);
        // …and the newest call dropped would not have fitted.
        $newestDropped = UpstreamTrail::normalise(
            $this->call('call-' . (11 - $kept), ['status' => 500, 'message' => str_repeat('x', 1000)]),
        );
        $this->assertGreaterThan(UpstreamTrail::MAX_BYTES, UpstreamTrail::byteLength([$newestDropped, ...$line['upstream']]));
    }

    public function testTheByteCapIsExact(): void
    {
        foreach ([8192 => 0, 8193 => 1] as $bytes => $expectedDropped) {
            // `message` is cut at 1024 characters, so the size is built from
            // several calls.
            $calls = $this->callsTotalling($bytes);
            $this->assertSame($bytes, UpstreamTrail::byteLength($calls));

            $trail = new UpstreamTrail();
            foreach ($calls as $seq => $call) {
                $normalised = UpstreamTrail::normalise($call);
                $this->assertIsArray($normalised);
                $trail->record($seq, $normalised);
            }
            $taken = $trail->take();
            $this->assertSame($expectedDropped, $taken['dropped'], "{$bytes} bytes");
        }
    }

    public function testBoundsMemoryAsCallsArriveNotOnlyAtTheResponse(): void
    {
        $trail = new UpstreamTrail();
        for ($i = 0; $i < 10000; $i++) {
            $trail->record($i, ['name' => "c{$i}", 'method' => 'GET', 'url' => 'u', 'durationMs' => 1]);
        }
        $entries = (new \ReflectionProperty(UpstreamTrail::class, 'entries'))->getValue($trail);
        $this->assertCount(UpstreamTrail::MAX_CALLS, $entries);
        $this->assertSame(9980, $trail->take()['dropped']);
    }

    public function testOrdersByStartSequenceNotArrival(): void
    {
        $trail = new UpstreamTrail();
        $trail->record(2, ['name' => 'started-third']);
        $trail->record(0, ['name' => 'started-first']);
        $trail->record(1, ['name' => 'started-second']);

        $this->assertSame(
            ['started-first', 'started-second', 'started-third'],
            array_column($trail->take()['calls'], 'name'),
        );
    }

    // ------------------------------------------------------------ coercion

    public function testStripsTheQueryStringFragmentAndUserinfo(): void
    {
        $this->assertSame('https://api.stripe.com/v1/customers', UpstreamTrail::stripUrl('https://user:pass@api.stripe.com/v1/customers?email=jo@example.com#frag'));
        $this->assertSame('/relative/path', UpstreamTrail::stripUrl('/relative/path?token=abc'));
        $this->assertSame('https://host', UpstreamTrail::stripUrl('https://u@host'));
        $this->assertSame('https://host/a@b', UpstreamTrail::stripUrl('https://host/a@b'), 'an @ in the path is not userinfo');
        $this->assertSame('https://host', UpstreamTrail::stripUrl('https://host#?x'));

        $logger = $this->logger();
        $this->exchange($logger, 'c', fn (Logger $l) => $l->upstream($this->call('s', ['url' => 'https://key:secret@api.test/v1/x?api_key=sk_live_123'])));
        $logger->flush();
        $this->assertSame('https://api.test/v1/x', $this->responses()[0]['upstream'][0]['url']);
        $this->assertStringNotContainsString('sk_live_123', implode('', $this->rawLines()));
        $this->assertStringNotContainsString('secret', implode('', $this->rawLines()));
    }

    public function testRejectsACallIngestWouldRejectAndReportsIt(): void
    {
        $cases = [
            'no name' => [['name' => ' '], '`name` must be a non-empty string'],
            'no method' => [['method' => null], '`method` must be a non-empty string'],
            'long method' => [['method' => str_repeat('A', 17)], '`method` must be at most 16 characters'],
            'email as method' => [['method' => 'jo@example.com'], '`method` must be an HTTP method (letters, `-`, `_`)'],
            'no url' => [['url' => ''], '`url` must be a non-empty string'],
            'status 99' => [['status' => 99], '`status` must be an HTTP status (100–599), or omitted for a network error'],
            'status 600' => [['status' => 600], '`status` must be an HTTP status (100–599), or omitted for a network error'],
            'status string' => [['status' => '200'], '`status` must be an HTTP status (100–599), or omitted for a network error'],
            'status fraction' => [['status' => 200.5], '`status` must be an HTTP status (100–599), or omitted for a network error'],
            'negative duration' => [['durationMs' => -1], '`durationMs` must be a finite, non-negative number'],
            'infinite duration' => [['durationMs' => INF], '`durationMs` must be a finite, non-negative number'],
            'string duration' => [['durationMs' => '5'], '`durationMs` must be a finite, non-negative number'],
        ];

        foreach ($cases as $label => [$override, $reason]) {
            $this->reported = [];
            $this->captured = [];
            $logger = $this->logger();
            $this->exchange($logger, 'c', fn (Logger $l) => $l->upstream(array_merge($this->call('x'), $override)));
            $logger->flush();

            $this->assertArrayNotHasKey('upstream', $this->responses()[0], "{$label}: never shipped");
            $this->assertCount(1, $this->reported, $label);
            $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
            $this->assertSame("logger.upstream(): {$reason} — call not recorded", $this->reported[0]->message, $label);
            $this->assertSame(0, $this->reported[0]->entryCount);
        }
    }

    public function testNetworkErrorsAndUnusableOptionalFields(): void
    {
        $normalise = fn (array $extra) => UpstreamTrail::normalise(array_merge($this->call('x'), $extra));

        // status absent, null or 0 → a network error: the key is omitted.
        foreach ([null, 0, 0.0] as $status) {
            $call = $normalise(['status' => $status, 'errorCode' => 'CURLE_COULDNT_CONNECT']);
            $this->assertArrayNotHasKey('status', $call);
            $this->assertSame('CURLE_COULDNT_CONNECT', $call['errorCode']);
        }
        $this->assertSame(201, $normalise(['status' => 201.0])['status'], 'JS numbers: 201.0 is 201');

        // Unusable optional fields are omitted, never a reason to lose the call.
        $call = $normalise([
            'requestId' => 'has spaces',
            'attempt' => 1001,
            'errorCode' => '   ',
            'message' => '',
        ]);
        $this->assertSame(['name', 'method', 'url', 'status', 'durationMs'], array_keys($call));
        $this->assertArrayNotHasKey('requestId', $normalise(['requestId' => str_repeat('r', 129)]));
        $this->assertArrayNotHasKey('requestId', $normalise(['requestId' => "r\u{e9}"]));
        $this->assertArrayNotHasKey('attempt', $normalise(['attempt' => -1]));
        $this->assertArrayNotHasKey('attempt', $normalise(['attempt' => '2']));
        $this->assertSame(0, $normalise(['attempt' => 0])['attempt']);
        $this->assertSame(' padded id', $normalise(['message' => ' padded id'])['message'], 'message is not trimmed');
        $this->assertSame('req_1', $normalise(['requestId' => '  req_1 '])['requestId'], 'requestId is trimmed');
        $this->assertSame('stripe', $normalise(['name' => '  stripe '])['name']);
    }

    public function testCutsOverLongTextFieldsToIngestsCaps(): void
    {
        $call = UpstreamTrail::normalise($this->call('x', [
            'name' => str_repeat('n', 200),
            'url' => 'https://h/' . str_repeat('p', 3000),
            'errorCode' => str_repeat('e', 200),
            'message' => str_repeat('m', 2000),
        ]));

        $this->assertSame(128, strlen($call['name']));
        $this->assertSame(2048, strlen($call['url']));
        $this->assertSame(128, strlen($call['errorCode']));
        $this->assertSame(1024, strlen($call['message']));
    }

    public function testCountsCharactersTheWayIngestDoes(): void
    {
        // é is one UTF-16 unit; 😀 is two. Ingest (JavaScript) counts units.
        $call = UpstreamTrail::normalise($this->call('x', [
            'name' => str_repeat("\u{e9}", 200),
            'message' => str_repeat("\u{1F600}", 600),
        ]));

        $this->assertSame(str_repeat("\u{e9}", 128), $call['name']);
        $this->assertSame(str_repeat("\u{1F600}", 512), $call['message'], '1024 units, never half a pair');
    }

    public function testInvalidUtf8InAFieldNeverCostsTheResponseLine(): void
    {
        $logger = $this->logger();
        $this->exchange($logger, 'corr-1', fn (Logger $l) => $l->upstream($this->call('bin', ['message' => "bad \xC3\x28 byte"])));
        $logger->flush();

        $line = $this->responses()[0];
        $this->assertSame("bad \u{FFFD}( byte", $line['upstream'][0]['message']);
        $this->assertSame([], $this->reported);
    }

    // ---------------------------------------- no response line left (rule 7)

    public function testATopLevelScopeThatEndsHoldingCallsDropsAndReportsThem(): void
    {
        $logger = $this->logger();
        $logger->runWithContext([], function () use ($logger): void {
            $logger->upstream($this->call('never-answered-1'));
            $logger->upstream($this->call('never-answered-2'));
        });
        $this->exchange($logger, 'unrelated');
        $logger->flush();

        $this->assertArrayNotHasKey('upstream', $this->responses()[0], 'never parked on the logger-wide trail');
        $this->assertSame(2, $logger->stats()['upstreamDropped']);
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
        $this->assertSame(0, $this->reported[0]->entryCount);
        $this->assertStringStartsWith(
            'logger.upstream(): dropped 2 upstream calls with no response line left to ship on — their request scope ended',
            $this->reported[0]->message,
        );
        $this->assertStringEndsWith('(2 dropped in total)', $this->reported[0]->message);
    }

    public function testAlsoWhenTheTopLevelFnThrows(): void
    {
        $logger = $this->logger();
        try {
            $logger->runWithContext([], function () use ($logger): void {
                $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => '/x']);
                $logger->upstream($this->call('then-it-threw'));
                throw new \LogicException('handler failed before logResponse');
            });
        } catch (\LogicException) {
        }

        $this->assertSame(1, $logger->stats()['upstreamDropped']);
        $this->assertStringStartsWith('logger.upstream(): dropped 1 upstream call with', $this->reported[0]->message);
    }

    public function testACallAfterTheLastResponseOfAnEndedScopeIsDropped(): void
    {
        $logger = $this->logger();
        $kept = $logger->runWithContext([], function (Logger $scoped): Logger {
            $this->exchange($scoped, 'corr-1', fn (Logger $l) => $l->upstream($this->call('shipped')));
            $scoped->upstream($this->call('after-the-response'));

            return $scoped;
        });
        $this->assertSame(1, $logger->stats()['upstreamDropped']);

        $kept->upstream($this->call('after-the-scope-ended'));
        $this->assertSame(2, $logger->stats()['upstreamDropped']);
        $logger->flush();
        $this->assertSame(['shipped'], $this->names($this->responses()[0]));
    }

    public function testATopLevelChildNeverEnds(): void
    {
        $logger = $this->logger();
        $child = $logger->child();
        $this->exchange($child, 'corr-1', fn (Logger $l) => $l->upstream($this->call('first')));
        $child->upstream($this->call('between-responses'));
        $this->exchange($child, 'corr-2');
        $logger->flush();

        [$one, $two] = $this->responses();
        $this->assertSame(['first'], $this->names($one));
        $this->assertSame(['between-responses'], $this->names($two));
        $this->assertSame(0, $logger->stats()['upstreamDropped']);
        $this->assertSame([], $this->reported, 'a discarded child is not reported either');
    }

    public function testReportsAtMostOnceAMinuteAndTheRestAtFlush(): void
    {
        $logger = $this->logger();
        $lose = function (int $calls) use ($logger): void {
            $logger->runWithContext([], function () use ($logger, $calls): void {
                for ($i = 0; $i < $calls; $i++) {
                    $logger->upstream($this->call("lost-{$i}"));
                }
            });
        };

        $lose(1);
        $this->now += 1000;
        $lose(2);
        $lose(3);
        $this->assertSame(6, $logger->stats()['upstreamDropped'], 'every drop is counted');
        $this->assertCount(1, $this->reported, 'one report per minute');

        $this->now += 60000;
        $lose(1);
        $this->assertCount(2, $this->reported);
        $this->assertStringStartsWith('logger.upstream(): dropped 6 upstream calls', $this->reported[1]->message, 'carries everything held back');
        $this->assertStringEndsWith('(7 dropped in total)', $this->reported[1]->message);

        $this->now += 10;
        $lose(4);
        $this->assertCount(2, $this->reported);
        $logger->flush();
        $this->assertCount(3, $this->reported, 'flush() reports what the rate limit held back');
        $this->assertStringStartsWith('logger.upstream(): dropped 4 upstream calls', $this->reported[2]->message);
        $logger->flush();
        $this->assertCount(3, $this->reported, 'and only once');
    }

    public function testTheBatchSizeDrainDoesNotReportHeldBackDropsEarly(): void
    {
        $logger = $this->logger(['batchSize' => 1]);
        $lose = fn () => $logger->runWithContext([], fn () => $logger->upstream($this->call('lost')));
        $lose();
        $lose();
        $this->assertCount(1, $this->reported);

        $logger->info(self::KEY, 'trips the batchSize drain');
        $this->assertCount(1, $this->captured, 'the drain ran');
        $this->assertCount(1, $this->reported, 'and did not report the held-back drop');
    }

    // --------------------------------------------- logger-wide warning (rule 5)

    public function testWarnsOnceWhenALoggerOutlivesItsRequestWithoutScopes(): void
    {
        $logger = $this->logger();
        // PHP-FPM: one request per process. Logger-wide is conformant; no noise.
        $this->exchange($logger, 'request-1', fn (Logger $l) => $l->upstream($this->call('one')));
        $this->assertSame([], $this->reported);

        // A second request on the same logger: a long-running worker.
        $this->exchange($logger, 'request-2', fn (Logger $l) => $l->upstream($this->call('two')));
        $this->exchange($logger, 'request-3', fn (Logger $l) => $l->upstream($this->call('three')));
        $logger->flush();

        $this->assertCount(1, $this->reported, 'once per logger');
        $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
        $this->assertStringContainsString('outside any request scope', $this->reported[0]->message);
        // Still shipped: a warning, not a drop.
        $this->assertSame([['one'], ['two'], ['three']], array_map(fn ($l) => $this->names($l), $this->responses()));
    }

    public function testScopedCallsNeverTriggerTheLoggerWideWarning(): void
    {
        $logger = $this->logger();
        for ($i = 0; $i < 3; $i++) {
            $logger->runWithContext([], fn () => $this->exchange($logger, "r{$i}", fn (Logger $l) => $l->upstream($this->call("c{$i}"))));
        }
        $this->assertSame([], $this->reported);
    }

    // --------------------------------------------------------- never throws

    public function testUpstreamNeverThrowsEvenWhenTheClockDoes(): void
    {
        $logger = $this->logger(['clock' => function (): float {
            throw new \RuntimeException('clock broke');
        }]);

        // An ended top-level scope consults the clock to rate-limit its report.
        $logger->runWithContext([], fn () => $logger->upstream($this->call('lost')));
        $logger->flush();

        $this->assertSame(1, $logger->stats()['upstreamDropped']);
        $this->assertStringContainsString('clock broke', $this->reportedMessages()[0]);
    }

    // ---------------------------------------------------------------- helpers

    private function expectedPlainResponse(): string
    {
        return '{"level":"info","message":"Outgoing response","path":"/orders","method":"GET","status_code":200,"duration_ms":12,"correlation_id":"corr-1","headers":null,"body":null}';
    }

    /**
     * Calls whose trail encodes to exactly `$bytes` bytes.
     *
     * @return list<array<string, mixed>>
     */
    private function callsTotalling(int $bytes): array
    {
        $calls = [];
        while (true) {
            $calls[] = $this->call('c' . count($calls), ['message' => str_repeat('m', 900)]);
            if (UpstreamTrail::byteLength($calls) >= $bytes) {
                break;
            }
        }
        $over = UpstreamTrail::byteLength($calls) - $bytes;
        $last = count($calls) - 1;
        $calls[$last]['message'] = str_repeat('m', 900 - $over);

        return $calls;
    }
}

<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PHPUnit\Framework\TestCase;

/**
 * The process-wide shutdown drain (FLT-1522).
 *
 * Until 2.1.1 every logger registered its own `register_shutdown_function`
 * with a closure over `$this`, so on a long-lived worker each per-request
 * logger stayed reachable — buffer, Guzzle client, options — until the
 * process exited. Now one hook is registered per process and visits the
 * loggers it holds weakly.
 *
 * `Logger::overrideShutdownHook()` captures the hook instead of handing it to
 * PHP (holding it as strongly as PHP would) and replaces
 * `fastcgi_finish_request()`; each test runs the captured hook itself.
 */
final class ShutdownDrainTest extends TestCase
{
    private const KEY = 'app-key';

    /** @var list<callable> The hooks "registered with PHP", in order. */
    private array $hooks = [];

    /** @var list<string> `finish`, and `post:<logger>` per POST, in order. */
    private array $events = [];

    /** @var list<LoggerErrorEvent> */
    private array $reported = [];

    /** Shared fake monotonic clock, in milliseconds. */
    private float $now = 0.0;

    protected function setUp(): void
    {
        $this->hooks = [];
        $this->events = [];
        $this->reported = [];
        $this->now = 0.0;

        Logger::overrideShutdownHook(
            function (callable $hook): void {
                $this->hooks[] = $hook;
            },
            function (): void {
                $this->events[] = 'finish';
            },
        );
    }

    protected function tearDown(): void
    {
        Logger::overrideShutdownHook();
    }

    public function testOneHookIsRegisteredPerProcessHoweverManyLoggersBuffer(): void
    {
        $loggers = [$this->logger('a'), $this->logger('b'), $this->logger('c')];
        foreach ($loggers as $logger) {
            $logger->info(self::KEY, 'one');
            $logger->info(self::KEY, 'two');
        }

        $this->assertCount(1, $this->hooks);
    }

    public function testALoggerThatNeverBuffersRegistersNothing(): void
    {
        $this->logger('a');

        $this->assertSame([], $this->hooks);
    }

    public function testTheHookDrainsEveryLiveLoggerWithABuffer(): void
    {
        $a = $this->logger('a');
        $b = $this->logger('b');
        $c = $this->logger('c');
        $a->info(self::KEY, 'one');
        $b->info(self::KEY, 'one');
        $c->info(self::KEY, 'one');

        $this->runHooks();

        $this->assertSame(['finish', 'post:a', 'post:b', 'post:c'], $this->events);
        foreach ([$a, $b, $c] as $logger) {
            $this->assertSame(0, $logger->stats()['buffered']);
        }
    }

    public function testFlushOnShutdownFalseIsNeitherRegisteredNorDrained(): void
    {
        $off = $this->logger('off', ['flushOnShutdown' => false]);
        $off->info(self::KEY, 'stays');

        $this->assertSame([], $this->hooks, 'opting out registers no hook');

        $on = $this->logger('on');
        $on->info(self::KEY, 'goes');
        $this->runHooks();

        $this->assertSame(['finish', 'post:on'], $this->events, 'the hook another logger registered skips it');
        $this->assertSame(1, $off->stats()['buffered']);
    }

    public function testAClosedLoggerIsNotVisitedUntilItBuffersAgain(): void
    {
        $logger = $this->logger('a');
        $logger->info(self::KEY, 'one');
        $logger->close();
        $this->assertSame(['post:a'], $this->events);

        $this->runHooks();

        $this->assertSame(['post:a'], $this->events, 'not visited: not even the response is finished for it');

        // close() leaves it usable: the next entry queues it again, under a
        // fresh hook (the first one has run).
        $logger->info(self::KEY, 'two');
        $this->runHooks();

        $this->assertSame(['post:a', 'finish', 'post:a'], $this->events);
    }

    public function testShutdownLeavesTheQueueToo(): void
    {
        $logger = $this->logger('a');
        $logger->info(self::KEY, 'one');
        $logger->shutdown();

        $this->runHooks();

        $this->assertSame(['post:a'], $this->events);
    }

    public function testTheResponseIsFinishedOnceAndBeforeAnyDelivery(): void
    {
        $a = $this->logger('a');
        $b = $this->logger('b');
        $a->info(self::KEY, 'one');
        $b->info(self::KEY, 'one');

        $this->runHooks();

        $this->assertSame(['finish', 'post:a', 'post:b'], $this->events);

        // A late entry registers a second hook; the response is already done.
        $a->info(self::KEY, 'late');
        $this->runHooks();

        $this->assertSame(['finish', 'post:a', 'post:b', 'post:a'], $this->events);
    }

    public function testFinishRequestOnShutdownFalseLeavesTheResponseAlone(): void
    {
        $logger = $this->logger('a', ['finishRequestOnShutdown' => false]);
        $logger->info(self::KEY, 'one');

        $this->runHooks();

        $this->assertSame(['post:a'], $this->events);
    }

    /**
     * Each logger's `drainDeadlineMs` counts from the moment the hook
     * started, so a blackholed first logger cannot buy the second a budget of
     * its own: the drain as a whole ends within one `drainDeadlineMs`.
     */
    public function testOneDrainDeadlineBoundsTheWholeShutdownDrain(): void
    {
        $options = ['drainDeadlineMs' => 1000, 'requestTimeoutMs' => 5000, 'maxRetries' => 0];
        $slow = $this->logger('slow', $options, blackhole: true);
        $next = $this->logger('next', $options);
        $slow->info(self::KEY, 'one');
        $next->info(self::KEY, 'one');

        $this->runHooks();

        $this->assertSame(1000.0, $this->now, 'the blackhole spent the whole budget');
        $this->assertSame(['finish', 'post:slow'], $this->events, 'nothing left to post the second with');
        $this->assertSame(0, $next->stats()['buffered']);
        $this->assertSame(1, $next->stats()['dropped']);
        $reasons = array_map(static fn (LoggerErrorEvent $event) => $event->reason, $this->reported);
        $this->assertContains(LoggerErrorEvent::REASON_DRAIN_TIMEOUT, $reasons);
    }

    public function testADroppedLoggerDeliversAsItIsDestroyedAndIsNotResurrected(): void
    {
        $logger = $this->logger('gone');
        $logger->info(self::KEY, 'one');
        $ref = \WeakReference::create($logger);

        unset($logger);
        gc_collect_cycles();

        $this->assertNull($ref->get(), 'the queue does not keep it alive');
        $this->assertSame(['post:gone'], $this->events, 'its buffer drained as it was destroyed');

        $this->runHooks();

        $this->assertSame(['post:gone'], $this->events, 'the hook finds nothing to visit');
    }

    public function testADroppedLoggerWithFlushOnShutdownOffDeliversNothing(): void
    {
        $logger = $this->logger('off', ['flushOnShutdown' => false]);
        $logger->info(self::KEY, 'one');

        unset($logger);
        gc_collect_cycles();

        $this->assertSame([], $this->events);
    }

    /**
     * The FLT-1522 leak: N per-request loggers, each with one entry and its
     * own transport, dropped in turn. None may stay reachable.
     */
    public function testLoggersThatGoOutOfScopeAreCollected(): void
    {
        $refs = [];
        for ($i = 0; $i < 200; $i++) {
            $logger = $this->logger("r$i");
            $logger->info(self::KEY, 'one');
            $refs[] = \WeakReference::create($logger);
        }
        unset($logger);
        gc_collect_cycles();

        $alive = count(array_filter($refs, static fn (\WeakReference $ref) => $ref->get() !== null));

        $this->assertLessThanOrEqual(1, $alive);
        $this->assertCount(1, $this->hooks);
        $this->assertCount(200, $this->events, 'and every one delivered, on its way out');
    }

    // ------------------------------------------------------------------- helpers

    /** Runs the hooks registered since the last call, as PHP would at exit. */
    private function runHooks(): void
    {
        while ($this->hooks !== []) {
            $hook = array_shift($this->hooks);
            $hook();
        }
    }

    /** @param array<string, mixed> $options */
    private function logger(string $name, array $options = [], bool $blackhole = false): Logger
    {
        return new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: new RecordingTransport(function (string $method, string $url, array $request) use ($name, $blackhole): Response {
                $this->events[] = "post:$name";
                if ($blackhole) {
                    // Burns exactly the deadline it was handed, then fails.
                    $this->now += (float) ($request['timeout'] ?? 0) * 1000;
                    throw new ConnectException(
                        'cURL error 28: Operation timed out',
                        new Request('POST', $url),
                    );
                }

                return new Response(200, [], '{}');
            }),
            timestampProvider: static fn () => 1234567890000,
            options: array_merge([
                'batchSize' => 0,
                'onError' => function (LoggerErrorEvent $event): void {
                    $this->reported[] = $event;
                },
                'sleeper' => function (int $milliseconds): void {
                    $this->now += $milliseconds;
                },
                'clock' => fn (): float => $this->now,
            ], $options),
        );
    }
}

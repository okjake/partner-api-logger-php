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
            // A long-lived worker, explicitly: weak holding and the
            // destroy-time drain. The per-request branch has its own tests.
            'cli',
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

    /**
     * The budget starts once the response is finished, so a slow
     * `fastcgi_finish_request()` (a large response still being written out)
     * does not eat the drain's `drainDeadlineMs` — as before 2.1.1.
     */
    public function testTheDrainBudgetStartsAfterTheResponseIsFinished(): void
    {
        Logger::overrideShutdownHook(
            function (callable $hook): void {
                $this->hooks[] = $hook;
            },
            function (): void {
                $this->events[] = 'finish';
                $this->now += 1000;
            },
        );
        $logger = $this->logger('a', ['drainDeadlineMs' => 1000]);
        $logger->info(self::KEY, 'one');

        $this->runHooks();

        $this->assertSame(['finish', 'post:a'], $this->events);
        $this->assertSame([], $this->reported);
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

    /**
     * The destroy-time drain can run mid-request — before the response, under
     * PHP-FPM — so it may cost no more than the `batchSize` drain: one attempt
     * per chunk, no backoff sleep, `autoDrainTimeoutMs` for the whole drain,
     * never `drainDeadlineMs` with retries. Wall-clock on purpose: the double
     * really hangs for the timeout the logger hands it, as a blackholed ingest
     * would, and the logger runs on its real monotonic clock.
     */
    public function testADroppedLoggersDrainIsBoundedByTheAutoDrainTimeout(): void
    {
        $attempts = [];
        $sleeps = [];
        $logger = new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: new RecordingTransport(static function (string $method, string $url, array $request) use (&$attempts): Response {
                $key = $request['headers']['x-api-key'];
                $attempts[$key] = ($attempts[$key] ?? 0) + 1;
                usleep((int) (((float) ($request['timeout'] ?? 0)) * 1e6));
                throw new ConnectException('cURL error 28: Operation timed out', new Request('POST', $url));
            }),
            options: [
                'batchSize' => 0,
                'autoDrainTimeoutMs' => 200,
                'drainDeadlineMs' => 5000,
                'requestTimeoutMs' => 5000,
                'maxRetries' => 3,
                'onError' => function (LoggerErrorEvent $event): void {
                    $this->reported[] = $event;
                },
                'sleeper' => static function (int $milliseconds) use (&$sleeps): void {
                    $sleeps[] = $milliseconds;
                },
            ],
        );
        // Three API keys: three groups, so three chunks to post.
        $logger->info('key-a', 'one');
        $logger->info('key-b', 'two');
        $logger->info('key-c', 'three');

        $started = hrtime(true);
        unset($logger);
        gc_collect_cycles();
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $this->assertLessThan(200 + 300, $elapsedMs, 'bounded by autoDrainTimeoutMs, not drainDeadlineMs');
        $this->assertSame([], $sleeps, 'no backoff');
        $this->assertSame([1], array_values(array_unique($attempts)), 'never more than one attempt per chunk');

        // The first chunk spends the budget (to within the clock's rounding,
        // which can leave a 1 ms attempt for the next); whatever the budget
        // never reached is a drain-timeout. Every entry is accounted for.
        $dropped = 0;
        foreach ($this->reported as $event) {
            $this->assertContains($event->reason, [LoggerErrorEvent::REASON_FLUSH_FAILED, LoggerErrorEvent::REASON_DRAIN_TIMEOUT]);
            if ($event->reason === LoggerErrorEvent::REASON_FLUSH_FAILED) {
                $this->assertSame(1, $event->attempts);
            } else {
                $this->assertStringContainsString('(200 ms)', $event->message, 'names the budget that ran out');
            }
            $dropped += $event->entryCount;
        }
        $this->assertSame(3, $dropped, 'every undelivered entry reported as a drop');
        $this->assertSame(LoggerErrorEvent::REASON_DRAIN_TIMEOUT, end($this->reported)->reason);
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

    /**
     * With ingest down and an `onError` that logs on the same logger (a
     * pattern the readme allows), every round fails and logs again. If each
     * entry logged during the drain registered a fresh hook, PHP would run
     * them forever and the process would never exit.
     */
    public function testAnOnErrorThatLogsCannotKeepTheHookRunning(): void
    {
        $logger = null;
        $logger = $this->logger('down', [
            'maxRetries' => 0,
            'onError' => function (LoggerErrorEvent $event) use (&$logger): void {
                $this->reported[] = $event;
                $logger->warn(self::KEY, 'delivery failed: ' . $event->reason);
            },
        ], failing: true);
        $logger->info(self::KEY, 'hello');

        $this->runHooks();

        $this->assertSame(['finish', 'post:down'], $this->events, 'one round, as in 2.1.0');
        $this->assertSame([], $this->hooks);
        $this->assertSame(1, $logger->stats()['buffered'], 'the onError line waits, queued');

        // Something logging after the hook (a later shutdown function)
        // registers a fresh one, which takes that line too — and ends.
        $logger->info(self::KEY, 'late');
        $this->assertCount(1, $this->hooks);
        $this->runHooks();

        // Two POSTs (warn and info are separate groups); their failures log
        // again, and again nothing re-registers.
        $this->assertSame(['finish', 'post:down', 'post:down', 'post:down'], $this->events);
        $this->assertSame([], $this->hooks);
    }

    /**
     * PHPUnit's `createMock(Logger::class)` and a subclass that skips
     * `parent::__construct()` both get an object whose typed properties were
     * never initialised. Destroying one must not throw into the caller.
     */
    public function testDestroyingALoggerWhoseConstructorNeverRanIsSilent(): void
    {
        $mock = $this->createMock(Logger::class);
        $mock->expects($this->once())->method('info');
        $mock->info(self::KEY, 'doubled');
        unset($mock);

        $subclass = new class () extends Logger {
            public function __construct()
            {
            }
        };
        unset($subclass);
        gc_collect_cycles();

        $this->assertSame([], $this->events);
    }

    /**
     * A caller-supplied `clock` that throws must neither escape a destructor
     * into whoever dropped the logger nor stop the shutdown hook before the
     * next logger. The entries it costs are reported, with their count.
     */
    public function testAThrowingClockNeitherEscapesADestructorNorStopsTheHook(): void
    {
        $broken = false;
        $clock = function () use (&$broken): float {
            if ($broken) {
                throw new \RuntimeException('clock broke');
            }

            return $this->now;
        };

        $dropped = $this->logger('dropped', ['clock' => $clock]);
        $dropped->info(self::KEY, 'one');
        $broken = true;
        unset($dropped);
        gc_collect_cycles();

        $this->assertSame([], $this->events);
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_FLUSH_FAILED, $this->reported[0]->reason);
        $this->assertSame(1, $this->reported[0]->entryCount, 'the lost entry is counted');
        $this->assertStringContainsString('clock broke', $this->reported[0]->message);

        $first = $this->logger('broken', ['clock' => $clock]);
        $second = $this->logger('healthy');
        $broken = false;
        $first->info(self::KEY, 'a');
        $second->info(self::KEY, 'b');
        $broken = true;

        $this->runHooks();

        $this->assertSame(['finish', 'post:healthy'], $this->events, 'the next logger still drains');
        $this->assertSame(1, $first->stats()['dropped']);
    }

    /**
     * Where every static resets when the request ends (PHP-FPM and friends)
     * nothing can leak, so 2.1.0's behaviour holds exactly: the hook keeps
     * the logger, delivers after the response is finished, and nothing
     * drains inside the request when a logger goes out of scope. Elsewhere
     * the logger is collected and drains as it goes.
     *
     * @return array<string, array{string, bool}>
     */
    public static function sapis(): array
    {
        return [
            'PHP-FPM' => ['fpm-fcgi', true],
            'FastCGI' => ['cgi-fcgi', true],
            'mod_php' => ['apache2handler', true],
            'built-in server (artisan serve)' => ['cli-server', true],
            'CLI (Octane, RoadRunner, queue:work)' => ['cli', false],
            'FrankenPHP (worker mode is long-lived)' => ['frankenphp', false],
            'phpdbg' => ['phpdbg', false],
        ];
    }

    /** @dataProvider sapis */
    public function testTheSapiDecidesBetweenHoldingAndDrainingOnDestruction(string $sapi, bool $perRequest): void
    {
        Logger::overrideShutdownHook(
            function (callable $hook): void {
                $this->hooks[] = $hook;
            },
            function (): void {
                $this->events[] = 'finish';
            },
            $sapi,
        );
        $logger = $this->logger('x');
        $logger->info(self::KEY, 'one');
        $ref = \WeakReference::create($logger);

        unset($logger);
        gc_collect_cycles();

        if ($perRequest) {
            $this->assertNotNull($ref->get(), 'held until the hook, as in 2.1.0');
            $this->assertSame([], $this->events, 'nothing drains inside the request');

            $this->runHooks();

            $this->assertSame(['finish', 'post:x'], $this->events, 'delivered after the response');
            gc_collect_cycles();
            $this->assertNull($ref->get(), 'and released once the hook has run');
        } else {
            $this->assertNull($ref->get(), 'collected');
            $this->assertSame(['post:x'], $this->events, 'drained as it was destroyed');

            $this->runHooks();

            $this->assertSame(['post:x'], $this->events);
        }
    }

    /**
     * `autoDrainTimeoutMs: 0` disables the bound; the destroy-time drain then
     * bounds each attempt by `requestTimeoutMs` rather than by nothing.
     */
    public function testTheDestroyTimeDrainFallsBackToRequestTimeoutWhenTheBudgetIsOff(): void
    {
        $timeouts = [];
        $logger = new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: new RecordingTransport(static function (string $method, string $url, array $request) use (&$timeouts): Response {
                $timeouts[] = $request['timeout'] ?? null;

                return new Response(200, [], '{}');
            }),
            options: ['batchSize' => 0, 'autoDrainTimeoutMs' => 0, 'requestTimeoutMs' => 750],
        );
        $logger->info(self::KEY, 'one');

        unset($logger);
        gc_collect_cycles();

        $this->assertSame([0.75], $timeouts);
    }

    // ------------------------------------------------------------------- helpers

    /**
     * Runs the hooks registered since the last call, as PHP would at exit —
     * including any a hook registers while it runs. Fails rather than spin if
     * they never stop coming.
     */
    private function runHooks(): void
    {
        for ($rounds = 0; $this->hooks !== []; $rounds++) {
            if ($rounds === 10) {
                $this->fail('the shutdown hook keeps re-registering itself');
            }
            $hook = array_shift($this->hooks);
            $hook();
        }
    }

    /** @param array<string, mixed> $options */
    private function logger(string $name, array $options = [], bool $blackhole = false, bool $failing = false): Logger
    {
        return new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: new RecordingTransport(function (string $method, string $url, array $request) use ($name, $blackhole, $failing): Response {
                $this->events[] = "post:$name";
                if ($failing) {
                    throw new ConnectException('cURL error 7: Failed to connect', new Request('POST', $url));
                }
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

<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests\Laravel;

use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PartnerApi\Logger\Laravel\LoggerServiceProvider;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\Tests\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * The provider's end-of-request drain on the servers it has to work on
 * (FLT-1522): PHP-FPM / `artisan serve`, where `terminate()` runs on the
 * application itself, and Octane, where it runs on a per-request clone.
 *
 * `octaneRequest()` reproduces `Laravel\Octane\Worker::handle()`: clone the
 * booted app, `CurrentApplication::set()` the clone (`app` and `Container`
 * point at it, as does `Container::getInstance()`), handle the request inside
 * it, call `terminate()` on it — the HTTP kernel's terminate() does that via
 * the app Octane gave it — then `flush()` it and drop it.
 */
final class LoggerServiceProviderTest extends TestCase
{
    private const KEY = 'app-key';

    /** @var list<string> Message of every line posted, in order. */
    private array $posted = [];

    /** Loggers the test factory has built. */
    private int $built = 0;

    /** @var list<string> Anything a logger reported through `onError`. */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->posted = [];
        $this->built = 0;
        $this->errors = [];
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame([], $this->errors, 'no logger reported a failure');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        Logger::overrideShutdownHook();
    }

    public function testTheProviderBindsOneLoggerPerContainerFromConfig(): void
    {
        $app = $this->bootApp(captureLoggers: false);

        $sandbox = $this->sandbox($app);
        $logger = $sandbox->make(Logger::class);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame($logger, $sandbox->make(Logger::class), 'a singleton within the request');
        $this->assertTrue($sandbox->resolved(Logger::class));
        $this->assertFalse($app->resolved(Logger::class), 'resolving in the sandbox leaves the booted app alone');
    }

    public function testOctaneDrainsTheLoggerEachRequestResolvedInItsSandbox(): void
    {
        $app = $this->bootApp();

        $first = $this->octaneRequest($app, 'one');
        $second = $this->octaneRequest($app, 'two');

        $this->assertSame(['one', 'two'], $this->posted, 'each request delivered at its own terminate()');
        $this->assertNotSame($first, $second, 'un-warmed: every request builds its own logger');
        $this->assertSame(0, $first->stats()['buffered']);
        $this->assertSame(0, $second->stats()['buffered']);
        $this->assertFalse($app->resolved(Logger::class), 'the booted app never resolved a logger');
    }

    public function testOctaneDrainsAWarmedLoggerAfterEveryRequest(): void
    {
        $app = $this->bootApp();
        $warmed = $app->make(Logger::class); // `warm` in config/octane.php

        $this->octaneRequest($app, 'one');
        $this->assertSame(['one'], $this->posted);

        $this->octaneRequest($app, 'two');
        $this->assertSame(['one', 'two'], $this->posted);

        $this->assertSame(1, $this->built, 'one logger serves the worker');
        $this->assertSame(0, $warmed->stats()['buffered']);
    }

    public function testWithoutASandboxTheApplicationsLoggerIsDrained(): void
    {
        // PHP-FPM / `artisan serve`: terminate() runs on the app itself.
        $app = $this->bootApp();

        $logger = $app->make(Logger::class);
        $logger->info(self::KEY, 'fpm');
        $this->assertSame([], $this->posted, 'buffered until the end of the request');

        $app->terminate();

        $this->assertSame(['fpm'], $this->posted);
    }

    public function testARequestThatNeverLoggedDoesNotBuildALogger(): void
    {
        $app = $this->bootApp();

        $sandbox = $this->sandbox($app);
        $sandbox->terminate();

        $this->assertFalse($sandbox->resolved(Logger::class));
        $this->assertSame(0, $this->built, 'nothing constructed just to flush nothing');
    }

    /**
     * The leak and the missed drain together, the way a worker meets them:
     * many un-warmed requests, each logger with the real shutdown-hook arming
     * on. Every request delivers at its own terminate(), and once its sandbox
     * is gone its logger is collectable.
     */
    public function testPerRequestLoggersAreDrainedAndNotRetainedAcrossOctaneRequests(): void
    {
        $hooks = [];
        Logger::overrideShutdownHook(static function (callable $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });
        $app = $this->bootApp(['flushOnShutdown' => true]);

        $refs = [];
        for ($i = 0; $i < 200; $i++) {
            $refs[] = \WeakReference::create($this->octaneRequest($app, "r$i"));
        }
        gc_collect_cycles();

        $alive = count(array_filter($refs, static fn (\WeakReference $ref) => $ref->get() !== null));

        $this->assertCount(200, $this->posted, 'every request delivered at its own terminate()');
        $this->assertSame(0, $alive, 'no request\'s logger outlives its sandbox');
        $this->assertCount(1, $hooks, 'one process-wide shutdown hook, not one per request');
    }

    // ------------------------------------------------------------------- helpers

    /**
     * A booted app with the provider registered. With `$captureLoggers` the
     * provider's Logger binding is replaced by one that posts to a recording
     * ingest double — same singleton shape, resolved by the same container —
     * so a drain is observable as a POST.
     *
     * @param array<string, mixed> $loggerOptions
     */
    private function bootApp(array $loggerOptions = [], bool $captureLoggers = true): FoundationApplication
    {
        $app = new FoundationApplication();
        $app->instance('config', new Repository([
            'partner-logger' => ['tenant_token' => 'tenant-token', 'base_url' => 'https://ingest.test'],
        ]));
        $app->register(LoggerServiceProvider::class);

        if ($captureLoggers) {
            $app->singleton(Logger::class, fn () => $this->recordingLogger($loggerOptions));
        }

        $app->boot();

        return $app;
    }

    /** @param array<string, mixed> $options */
    private function recordingLogger(array $options): Logger
    {
        $this->built++;

        return new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: new RecordingTransport(function (string $method, string $url, array $request): Response {
                foreach ($request['json']['entries'] as $entry) {
                    $this->posted[] = json_decode($entry['line'], true, 512, JSON_THROW_ON_ERROR)['message'];
                }

                return new Response(200, [], '{}');
            }),
            options: array_merge([
                // Off, so the only drain in play is the provider's.
                'flushOnShutdown' => false,
                'batchSize' => 0,
                'onError' => function (LoggerErrorEvent $event): void {
                    $this->errors[] = $event->message;
                },
            ], $options),
        );
    }

    /** `CurrentApplication::set($sandbox = clone $app)`, as Octane's Worker does. */
    private function sandbox(FoundationApplication $app): FoundationApplication
    {
        $sandbox = clone $app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        Container::setInstance($sandbox);

        return $sandbox;
    }

    /** One Octane request that logs `$message`; returns the logger it used. */
    private function octaneRequest(FoundationApplication $app, string $message): Logger
    {
        $sandbox = $this->sandbox($app);

        $logger = $sandbox->make(Logger::class);
        $logger->info(self::KEY, $message);

        $sandbox->terminate();
        $sandbox->flush();

        return $logger;
    }
}

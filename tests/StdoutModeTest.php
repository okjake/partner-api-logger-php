<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\LoggerException;
use PartnerApi\Logger\PartnerReference;
use PHPUnit\Framework\TestCase;

/**
 * Stdout mode (PAPI-5498, spec § Pipeline profile): what the shared fixtures
 * in {@see PipelineFixtureTest} cannot express — the sinks, failures, PHP's
 * own encoding traps, and that no buffered machinery runs.
 */
final class StdoutModeTest extends TestCase
{
    private const KEY = 'app-key-123';
    private const TOKEN = 'tenant_live_stdout';
    private const PREFIX = '{"partnerapi_line":"1.6.0",';

    /** @var list<string> */
    private array $lines = [];

    /** @var list<LoggerErrorEvent> */
    private array $reported = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->lines = [];
        $this->reported = [];
        RecordingStreamWrapper::reset();
        if (!in_array(RecordingStreamWrapper::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(RecordingStreamWrapper::SCHEME, RecordingStreamWrapper::class);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        foreach ($this->tempDirs as $dir) {
            @chmod($dir, 0700);
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        Logger::overrideShutdownHook();
    }

    // ------------------------------------------------------------- the sinks

    public function testTheDefaultSinkIsTheProcessStandardOutput(): void
    {
        [$stdout, $stderr, $exit] = $this->runPhp(<<<'PHP'
            $logger = new PartnerApi\Logger\Logger('tenant_live_test123', timestampProvider: fn () => 1234567890000, options: ['mode' => 'stdout']);
            $logger->info('test-api-key', 'to stdout');
            PHP);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stderr);
        $this->assertSame(
            '{"partnerapi_line":"1.6.0","partnerapi_partner_ref":"v1:716768d2853ca9bc0568918e2d3b99e80f0929fb4ccd6c0ca6a2d7558077c676",'
            . '"partnerapi_timestamp":"1234567890000000000","partnerapi_level":"info","level":"info","message":"to stdout"}' . "\n",
            $stdout,
        );
    }

    public function testPhpStderrIsASink(): void
    {
        [$stdout, $stderr, $exit] = $this->runPhp(<<<'PHP'
            $logger = new PartnerApi\Logger\Logger('tenant_live_test123', options: ['mode' => 'stdout', 'stdoutSink' => 'php://stderr']);
            $logger->info('test-api-key', 'to stderr');
            PHP);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith(self::PREFIX, $stderr);
        $this->assertSame(1, substr_count($stderr, "\n"));
    }

    public function testAFilePathSinkIsAppendedToByEveryLogger(): void
    {
        $file = $this->tempFile();
        file_put_contents($file, "existing line\n");

        $first = $this->logger(['stdoutSink' => $file]);
        $second = $this->logger(['stdoutSink' => $file]);
        $first->info(self::KEY, 'one');
        $second->info(self::KEY, 'two');
        $first->info(self::KEY, 'three');
        $first->flush();
        $second->close();

        $lines = explode("\n", (string) file_get_contents($file));
        $this->assertSame('existing line', $lines[0], 'append, never truncate');
        $this->assertSame(['one', 'two', 'three'], array_map(
            static fn (string $line) => json_decode($line, true)['message'],
            array_slice($lines, 1, 3),
        ));
        $this->assertSame('', $lines[4], 'every line ends in exactly one \n');
        $this->assertSame([], $this->reported);
    }

    public function testEachLineIsOneWriteToAStreamResourceTheLoggerNeverCloses(): void
    {
        $stream = fopen(RecordingStreamWrapper::SCHEME . '://sink', 'ab');
        $logger = $this->logger(['stdoutSink' => $stream]);

        $logger->info(self::KEY, 'one', ['n' => 1]);
        $logger->warn(self::KEY, 'two');
        $logger->shutdown();

        $this->assertCount(2, RecordingStreamWrapper::$writes, 'one write per line');
        foreach (RecordingStreamWrapper::$writes as $write) {
            $this->assertStringStartsWith(self::PREFIX, $write);
            $this->assertStringEndsWith("}\n", $write);
        }
        $this->assertSame(1, RecordingStreamWrapper::$flushes, 'flush() flushes the stream');
        $this->assertTrue(is_resource($stream), 'the caller\'s stream stays open');
        fclose($stream);
    }

    public function testACallableSinkGetsEachCompleteLineOnce(): void
    {
        $logger = $this->logger();
        $logger->info(self::KEY, 'one');
        $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => '/x', 'headers' => ['x-correlation-id' => 'c']]);

        $this->assertCount(2, $this->lines);
        foreach ($this->lines as $line) {
            $this->assertStringStartsWith(self::PREFIX, $line);
            $this->assertSame(1, substr_count($line, "\n"));
            $this->assertStringEndsWith("\n", $line);
        }
        $this->assertSame(['buffered' => 0, 'delivered' => 2, 'dropped' => 0, 'upstreamDropped' => 0], $logger->stats());
    }

    // ------------------------------------------------------- failing sinks

    public function testASinkThatThrowsIsReportedAndTheCallDoesNotThrow(): void
    {
        $boom = new \RuntimeException('disk on fire');
        $logger = $this->logger(['stdoutSink' => static function (string $line) use ($boom): void {
            throw $boom;
        }]);

        $logger->info(self::KEY, 'lost');

        $this->assertCount(1, $this->reported);
        $event = $this->reported[0];
        $this->assertSame(LoggerErrorEvent::REASON_WRITE_FAILED, $event->reason);
        $this->assertSame('Failed to write log: disk on fire', $event->message);
        $this->assertSame(1, $event->entryCount);
        $this->assertSame(1, $event->droppedTotal);
        $this->assertSame($boom, $event->cause);
        $this->assertSame(['buffered' => 0, 'delivered' => 0, 'dropped' => 1, 'upstreamDropped' => 0], $logger->stats());
    }

    public function testAnOnErrorHookThatLogsWhileTheSinkFailsDoesNotRecurse(): void
    {
        $logger = null;
        $logger = $this->logger([
            'stdoutSink' => static function (string $line): void {
                throw new \RuntimeException('sink down');
            },
            'onError' => function (LoggerErrorEvent $event) use (&$logger): void {
                $this->reported[] = $event;
                $logger->error(self::KEY, 'logging the failure');
            },
        ]);

        $logger->info(self::KEY, 'lost');

        $this->assertCount(1, $this->reported, 'the hook\'s own lost line is counted, not reported again');
        $this->assertSame(2, $logger->stats()['dropped']);
    }

    public function testASinkThatLogsThroughItsOwnLoggerDoesNotRecurse(): void
    {
        $logger = null;
        $written = [];
        $logger = $this->logger([
            'stdoutSink' => function (string $line) use (&$logger, &$written): void {
                $written[] = $line;
                $logger->debug(self::KEY, 'from inside the sink');
            },
        ]);

        $logger->info(self::KEY, 'outer');

        $this->assertCount(1, $written);
        $this->assertStringContainsString('"message":"outer"', $written[0]);
        $this->assertCount(1, $this->reported);
        $this->assertSame(
            'Failed to write log: the sink logged through the logger it is writing for',
            $this->reported[0]->message,
        );
        $this->assertSame(['buffered' => 0, 'delivered' => 1, 'dropped' => 1, 'upstreamDropped' => 0], $logger->stats());
    }

    public function testASinkThatCannotBeOpenedIsReportedWithTheOsErrorAndRetried(): void
    {
        $dir = sys_get_temp_dir() . '/papi-5498-missing-' . bin2hex(random_bytes(4));
        $logger = $this->logger(['stdoutSink' => "{$dir}/lines.log"]);

        $logger->info(self::KEY, 'first');
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_WRITE_FAILED, $this->reported[0]->reason);
        $this->assertStringStartsWith("Failed to write log: could not open {$dir}/lines.log: ", $this->reported[0]->message);
        $this->assertStringContainsString('No such file or directory', $this->reported[0]->message);

        // The directory appears (a volume mounted late): the next line opens it.
        mkdir($dir);
        $this->tempFiles[] = "{$dir}/lines.log";
        $logger->info(self::KEY, 'second');
        $this->assertCount(1, $this->reported);
        $this->assertStringContainsString('"message":"second"', (string) file_get_contents("{$dir}/lines.log"));
        unlink("{$dir}/lines.log");
        rmdir($dir);
    }

    public function testAClosedStreamIsReportedNotThrown(): void
    {
        $stream = fopen('php://memory', 'wb');
        $logger = $this->logger(['stdoutSink' => $stream]);
        fclose($stream);

        $logger->info(self::KEY, 'lost');
        $logger->flush();

        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_WRITE_FAILED, $this->reported[0]->reason);
    }

    public function testAShortWriteIsReportedAndTheFragmentIsEndedSoTheNextLineKeepsItsPrefix(): void
    {
        $stream = fopen(RecordingStreamWrapper::SCHEME . '://sink', 'ab');
        $logger = $this->logger(['stdoutSink' => $stream]);
        // The sink takes 10 bytes and then refuses the rest of that line.
        RecordingStreamWrapper::$plan = [10, 0];

        $logger->info(self::KEY, 'cut short');
        $logger->info(self::KEY, 'whole');

        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_WRITE_FAILED, $this->reported[0]->reason);
        $this->assertMatchesRegularExpression(
            '/^Failed to write log: (?:.+ \()?wrote 10 of \d+ bytes\)?$/',
            $this->reported[0]->message,
        );

        $written = implode('', RecordingStreamWrapper::$writes);
        [$fragment, $next] = explode("\n", $written, 2);
        $this->assertSame(substr(self::PREFIX, 0, 10), $fragment, 'the fragment is ended with one "\n"');
        $this->assertStringStartsWith(self::PREFIX, $next, 'the next line starts at a line start');
        $this->assertStringContainsString('"message":"whole"', $next);
        $this->assertSame(['buffered' => 0, 'delivered' => 1, 'dropped' => 1, 'upstreamDropped' => 0], $logger->stats());
        fclose($stream);
    }

    // ------------------------------------------- no buffered-profile machinery

    public function testLogCallsTouchNeitherTheNetworkNorTheShutdownHook(): void
    {
        $registered = 0;
        Logger::overrideShutdownHook(static function () use (&$registered): void {
            $registered++;
        });
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->never())->method('request');

        // batchSize 1 would drain on every call in buffered mode.
        $logger = $this->logger(['batchSize' => 1, 'flushOnShutdown' => true], $http);
        for ($i = 0; $i < 5; $i++) {
            $logger->info(self::KEY, "line {$i}");
        }
        $logger->child(['partnerId' => 'p'])->warn(self::KEY, 'child');
        $logger->flush();
        $logger->shutdown();
        $logger->close();
        $logger->flush();

        $this->assertSame(0, $registered, 'no end-of-request drain is armed');
        $this->assertCount(6, $this->lines, 'every line written at its call');
        $this->assertSame(0, $logger->stats()['buffered']);
        $this->assertSame([], $this->reported);
    }

    public function testMetricsStillPostInStdoutMode(): void
    {
        $posted = [];
        $logger = $this->logger([], new RecordingTransport(function (string $method, string $url) use (&$posted): Response {
            $posted[] = $url;

            return new Response(200, [], '{}');
        }));

        $logger->metric(self::KEY, ['slug' => 'bookings', 'timestamp' => '2026-10-02T00:00:00Z', 'value' => 1, 'period' => 'day']);

        $this->assertSame(['https://ingest.test/metrics'], $posted);
        $this->assertSame([], $this->lines);
    }

    // ------------------------------------------------------------ the line

    public function testTheEnvelopeComesFirstAndCarriesTheCallLevelAndContextAttribution(): void
    {
        $logger = $this->logger();
        $logger->runWithContext(
            ['direction' => 'outbound', 'upstreamIntegration' => 'stripe', 'upstreamBaseUrl' => ''],
            function (Logger $scoped): void {
                $scoped->error(self::KEY, 'Charge failed', ['level' => 50]);
            },
        );

        $this->assertSame(
            '{"partnerapi_line":"1.6.0","partnerapi_partner_ref":"' . PartnerReference::v1(self::TOKEN, self::KEY) . '",'
            . '"partnerapi_timestamp":"1234567890000000000","partnerapi_level":"error",'
            . '"partnerapi_direction":"outbound","partnerapi_upstream_integration":"stripe",'
            . '"level":50,"message":"Charge failed"}' . "\n",
            $this->lines[0],
            'an empty upstreamBaseUrl is omitted; the data level overrides the line level only',
        );
    }

    public function testIntegerLikeKeysKeepTheirNamesAndNestedReservedKeysAreKept(): void
    {
        // PHP stores '2024' and '7' as int keys; array_merge() would renumber
        // them to 0 and 1.
        $this->logger()->info(self::KEY, 'Yearly', [
            '2024' => 'x',
            '7' => 1,
            'nested' => ['partnerapi_inner' => true, '10' => 'a'],
            'partnerapi_partner_ref' => 'v1:spoof',
        ]);

        $line = $this->lines[0];
        $this->assertStringStartsWith(self::PREFIX, $line);
        $this->assertStringContainsString('"2024":"x","7":1,"nested":{"partnerapi_inner":true,"10":"a"}', $line);
        $this->assertStringNotContainsString('spoof', $line);
        $this->assertSame(1, substr_count($line, 'partnerapi_partner_ref'));
    }

    public function testEmptyHeadersAreWrittenAsAnObjectAndAbsentOrNullHeadersAreOmitted(): void
    {
        $logger = $this->logger();
        $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => '/a', 'headers' => []]);
        $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => '/b']);
        $logger->logRequest(self::KEY, ['method' => 'GET', 'path' => '/c', 'headers' => null]);
        $logger->logResponse(self::KEY, ['statusCode' => 204, 'duration' => 3, 'correlationId' => 'c', 'headers' => []]);
        $logger->logResponse(self::KEY, ['statusCode' => 204, 'duration' => 3, 'correlationId' => 'c']);
        $logger->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 3, 'correlationId' => 'c', 'headers' => null, 'body' => null]);

        $this->assertStringContainsString('"headers":{}', $this->lines[0]);
        $this->assertStringNotContainsString('"headers"', $this->lines[1]);
        $this->assertStringNotContainsString('"headers"', $this->lines[2]);
        $this->assertStringContainsString('"headers":{}', $this->lines[3]);
        $this->assertStringNotContainsString('"headers"', $this->lines[4]);
        $this->assertStringNotContainsString('"body"', $this->lines[4], 'an absent body is omitted');
        $this->assertStringNotContainsString('"headers"', $this->lines[5]);
        $this->assertStringContainsString('"body":null', $this->lines[5], 'an explicit null body is written');
        $this->assertSame([], $this->reported);
    }

    public function testAHeaderValueEqualToTheAppKeyIsRedactedWhateverTheHeaderAndPerValue(): void
    {
        $this->logger()->logRequest(self::KEY, [
            'method' => 'GET',
            'path' => '/x',
            'headers' => [
                'X-Partner-Key' => self::KEY,
                'x-multi' => ['a', self::KEY],
                'x-near' => self::KEY . ' ',
                'Authorization' => 'Bearer t',
                '0' => 'numeric header name',
            ],
        ]);

        $line = json_decode($this->lines[0], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('[REDACTED]', $line->headers->{'X-Partner-Key'});
        $this->assertSame(['a', '[REDACTED]'], $line->headers->{'x-multi'});
        $this->assertSame(self::KEY . ' ', $line->headers->{'x-near'}, 'exact match only');
        $this->assertSame('[REDACTED]', $line->headers->Authorization);
        $this->assertSame('numeric header name', $line->headers->{'0'}, 'a header named "0" stays a key');
        $this->assertStringNotContainsString('"' . self::KEY . '"', $this->lines[0]);
    }

    public function testInvalidUtf8IsSubstitutedNotDropped(): void
    {
        $this->logger()->info(self::KEY, "bad \xC3\x28 byte", ["k\xFF" => "v\xFE"]);

        $this->assertSame([], $this->reported);
        $line = json_decode($this->lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("bad \u{FFFD}( byte", $line['message']);
        $this->assertSame("v\u{FFFD}", $line["k\u{FFFD}"]);
    }

    public function testLargeIntegersAreWrittenExactly(): void
    {
        $this->logger()->info(self::KEY, 'big', ['max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'beyond_2_53' => 9007199254740993]);

        $this->assertStringContainsString(
            '"max":9223372036854775807,"min":-9223372036854775808,"beyond_2_53":9007199254740993',
            $this->lines[0],
        );
    }

    public function testUnserialisableDataIsReportedAndNothingIsWritten(): void
    {
        $logger = $this->logger();
        $logger->info(self::KEY, 'nan', ['x' => NAN]);
        $logger->info(self::KEY, 'throws', ['x' => new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('no');
            }
        }]);
        $logger->info('', 'no key');

        $this->assertSame([], $this->lines);
        $this->assertSame([
            'Failed to send log: log entry could not be serialised: Inf and NaN cannot be JSON encoded',
            'Failed to send log: log entry could not be built: no',
            'API key is required for logging',
        ], array_map(static fn (LoggerErrorEvent $e) => $e->message, $this->reported));
        $this->assertSame(
            [LoggerErrorEvent::REASON_INVALID_ENTRY, LoggerErrorEvent::REASON_INVALID_ENTRY, LoggerErrorEvent::REASON_INVALID_ENTRY],
            array_map(static fn (LoggerErrorEvent $e) => $e->reason, $this->reported),
        );
    }

    public function testABadTimestampIsReportedWithThePushPathMessage(): void
    {
        $logger = new Logger(
            tenantToken: self::TOKEN,
            timestampProvider: static fn () => 'soon',
            options: $this->options([]),
        );
        $logger->info(self::KEY, 'x');

        $this->assertSame([], $this->lines);
        $this->assertSame(
            'Failed to send log: log entry could not be built: timestampProvider must return epoch milliseconds'
            . ' as an int, float, numeric string or Stringable, got non-numeric string',
            $this->reported[0]->message,
        );
    }

    public function testEveryKeyGetsItsOwnReferenceAndTheRawKeyIsNeverWritten(): void
    {
        $logger = $this->logger();
        // More distinct keys than the reference cache holds, then the first again.
        for ($i = 0; $i < 300; $i++) {
            $logger->info("key-{$i}", 'x');
        }
        $logger->info('key-0', 'again');

        $this->assertCount(301, $this->lines);
        foreach ([0, 150, 299] as $i) {
            $line = json_decode($this->lines[$i], true);
            $this->assertSame(PartnerReference::v1(self::TOKEN, "key-{$i}"), $line['partnerapi_partner_ref']);
        }
        $this->assertSame(
            PartnerReference::v1(self::TOKEN, 'key-0'),
            json_decode($this->lines[300], true)['partnerapi_partner_ref'],
        );
        $all = implode('', $this->lines);
        $this->assertStringNotContainsString('"key-1"', $all);
        $this->assertStringNotContainsString(self::TOKEN, $all);
    }

    public function testTheUpstreamTrailRidesOnTheResponseLineInAChildScope(): void
    {
        $logger = $this->logger();
        $child = $logger->child(['partnerId' => 'p-1']);
        $child->logRequest(self::KEY, ['method' => 'POST', 'path' => '/orders', 'headers' => ['x-correlation-id' => 'c-1']]);
        $child->upstream(['name' => 'stripe', 'method' => 'POST', 'url' => 'https://api.stripe.com/v1/charges?x=1', 'status' => 200, 'durationMs' => 12]);
        $child->logResponse(self::KEY, ['statusCode' => 201, 'duration' => 30, 'correlationId' => 'c-1']);

        $request = json_decode($this->lines[0], true);
        $response = json_decode($this->lines[1], true);
        $this->assertArrayNotHasKey('upstream', $request);
        $this->assertSame('p-1', $response['partnerId']);
        $this->assertSame(
            [['name' => 'stripe', 'method' => 'POST', 'url' => 'https://api.stripe.com/v1/charges', 'status' => 200, 'durationMs' => 12]],
            $response['upstream'],
        );
    }

    // -------------------------------------------- cycle 2: guards and limits

    /**
     * A sink write that suspends (a Fiber here; a Swoole coroutine hook in
     * production) lets another request log meanwhile. That is not
     * re-entrance: both lines are written.
     */
    public function testRequestsInterleavedByFibersThroughASuspendingSinkBothWrite(): void
    {
        $written = [];
        $logger = $this->logger(['stdoutSink' => function (string $line) use (&$written): void {
            if (\Fiber::getCurrent() !== null) {
                \Fiber::suspend();
            }
            $written[] = $line;
        }]);

        $a = new \Fiber(static fn () => $logger->child(['partnerId' => 'a'])->info(self::KEY, 'from a'));
        $b = new \Fiber(static fn () => $logger->child(['partnerId' => 'b'])->info(self::KEY, 'from b'));
        $a->start();
        $b->start();
        $a->resume();
        $b->resume();

        $this->assertTrue($a->isTerminated() && $b->isTerminated());
        $this->assertSame([], array_map(static fn (LoggerErrorEvent $e) => $e->message, $this->reported));
        $this->assertCount(2, $written);
        $this->assertStringContainsString('"message":"from a"', $written[0]);
        $this->assertStringContainsString('"message":"from b"', $written[1]);
    }

    public function testASinkThatLogsThroughItsOwnLoggerInsideAFiberIsStillGuarded(): void
    {
        $logger = null;
        $written = [];
        $logger = $this->logger(['stdoutSink' => function (string $line) use (&$logger, &$written): void {
            $written[] = $line;
            $logger->debug(self::KEY, 'from inside the sink');
        }]);

        $fiber = new \Fiber(static fn () => $logger->info(self::KEY, 'outer'));
        $fiber->start();

        $this->assertTrue($fiber->isTerminated());
        $this->assertCount(1, $written);
        $this->assertSame(
            ['Failed to write log: the sink logged through the logger it is writing for'],
            array_map(static fn (LoggerErrorEvent $e) => $e->message, $this->reported),
        );
    }

    public function testAFileRotatedAwayIsReopenedWithinASecond(): void
    {
        $now = 0.0;
        $file = $this->tempFile();
        $this->tempFiles[] = "{$file}.1";
        $logger = $this->logger(['stdoutSink' => $file, 'clock' => static function () use (&$now): float {
            return $now;
        }]);

        $logger->info(self::KEY, 'before');
        // logrotate's default: rename the file away, create a new one.
        rename($file, "{$file}.1");
        touch($file);

        $now = 999.0;
        $logger->info(self::KEY, 'inside the check interval');
        $now = 1000.0;
        $logger->info(self::KEY, 'after');

        $old = (string) file_get_contents("{$file}.1");
        $new = (string) file_get_contents($file);
        $this->assertStringContainsString('"message":"before"', $old);
        $this->assertStringContainsString('"message":"inside the check interval"', $old, 'checked at most once a second');
        $this->assertStringNotContainsString('"message":"after"', $old);
        $this->assertStringContainsString('"message":"after"', $new, 'the new file receives the line');
        $this->assertSame(1, substr_count($new, "\n"));
        $this->assertSame([], $this->reported);
    }

    /**
     * `stat()` of the path fails because its directory cannot be searched —
     * not because the file is gone. The open stream still writes, so it is
     * kept: nothing is lost and nothing is reported.
     */
    public function testAStatFailureThatIsNotARotationKeepsTheWorkingStream(): void
    {
        $this->skipIfPermissionsDoNotApply();
        $now = 0.0;
        $dir = $this->tempDir();
        $file = "{$dir}/lines.log";
        $logger = $this->logger(['stdoutSink' => $file, 'clock' => static function () use (&$now): float {
            return $now;
        }]);

        $logger->info(self::KEY, 'one');
        chmod($dir, 0000);
        try {
            clearstatcache();
            $this->assertFalse(@stat($file), 'precondition: stat fails while the directory is unsearchable');
            $now = 1000.0;
            $logger->info(self::KEY, 'two');
            $now = 2000.0;
            $logger->info(self::KEY, 'three');
        } finally {
            chmod($dir, 0700);
        }

        $this->assertSame([], $this->reported, 'no line lost, nothing reported');
        $this->assertSame(['one', 'two', 'three'], $this->messagesIn($file));
        $this->assertSame(3, $logger->stats()['delivered']);
    }

    /**
     * Rotated away, but the new file cannot be created yet: the lines keep
     * going to the old descriptor, and the first check after the path is
     * openable switches over.
     */
    public function testARotatedFileThatCannotBeReopenedYetKeepsTheOldStreamUntilItCan(): void
    {
        $this->skipIfPermissionsDoNotApply();
        $now = 0.0;
        $dir = $this->tempDir();
        $file = "{$dir}/lines.log";
        $logger = $this->logger(['stdoutSink' => $file, 'clock' => static function () use (&$now): float {
            return $now;
        }]);

        $logger->info(self::KEY, 'one');
        rename($file, "{$file}.1");
        chmod($dir, 0500); // searchable, but nothing can be created in it
        try {
            $now = 1000.0;
            $logger->info(self::KEY, 'two');
            $this->assertFileDoesNotExist($file);
        } finally {
            chmod($dir, 0700);
        }
        $now = 1500.0;
        $logger->info(self::KEY, 'three, before the next check');
        $now = 2000.0;
        $logger->info(self::KEY, 'four, after it');

        $this->assertSame([], $this->reported);
        $this->assertSame(['one', 'two', 'three, before the next check'], $this->messagesIn("{$file}.1"));
        $this->assertSame(['four, after it'], $this->messagesIn($file));
    }

    public function testAFileDeletedOutrightIsRecreated(): void
    {
        $now = 0.0;
        $file = $this->tempFile();
        $logger = $this->logger(['stdoutSink' => $file, 'clock' => static function () use (&$now): float {
            return $now;
        }]);

        $logger->info(self::KEY, 'before');
        unlink($file);
        $now = 1000.0;
        $logger->info(self::KEY, 'after');

        $this->assertStringContainsString('"message":"after"', (string) file_get_contents($file));
        $this->assertSame([], $this->reported);
    }

    public function testCopytruncateKeepsWritingToTheSameFile(): void
    {
        $now = 0.0;
        $file = $this->tempFile();
        $logger = $this->logger(['stdoutSink' => $file, 'clock' => static function () use (&$now): float {
            return $now;
        }]);

        $logger->info(self::KEY, 'before');
        file_put_contents($file, ''); // copytruncate: same inode, emptied
        $now = 5000.0;
        $logger->info(self::KEY, 'after');

        $content = (string) file_get_contents($file);
        $this->assertStringStartsWith(self::PREFIX, $content, 'appends at the new end, no gap');
        $this->assertStringContainsString('"message":"after"', $content);
        $this->assertSame([], $this->reported);
    }

    public function testWriteFailuresInSeparateWindowsAreReportedSeparately(): void
    {
        $now = 0.0;
        $logger = $this->logger([
            'stdoutSink' => static function (string $line): void {
                throw new \RuntimeException('sink down');
            },
            'clock' => static function () use (&$now): float {
                return $now;
            },
        ]);

        $logger->info(self::KEY, 'one');
        $now = 60000.0;
        $logger->info(self::KEY, 'two');

        $this->assertSame(
            ['Failed to write log: sink down', 'Failed to write log: sink down'],
            array_map(static fn (LoggerErrorEvent $e) => $e->message, $this->reported),
        );
        $this->assertSame([1, 1], array_map(static fn (LoggerErrorEvent $e) => $e->entryCount, $this->reported));
    }

    /**
     * A closed stdout fails every write. One report per window, not per line;
     * every line still counted; the OS's own error in the message; and the
     * host's error handler and last-error state left alone.
     */
    public function testWriteFailuresAreRateLimitedAndCarryTheOsError(): void
    {
        [$ours, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($peer);
        $logger = $this->logger(['stdoutSink' => $ours]);

        $hostSaw = [];
        set_error_handler(static function (int $level, string $message) use (&$hostSaw): bool {
            $hostSaw[] = $message;

            return true;
        });
        try {
            for ($i = 0; $i < 1000; $i++) {
                $logger->info(self::KEY, "line {$i}");
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $hostSaw, 'the write warning never reached the host\'s handler');
        $this->assertCount(1, $this->reported, 'one report for 1000 lost lines inside one window');
        $this->assertSame(LoggerErrorEvent::REASON_WRITE_FAILED, $this->reported[0]->reason);
        $this->assertStringStartsWith('Failed to write log: ', $this->reported[0]->message);
        $this->assertStringContainsString('Broken pipe', $this->reported[0]->message);
        $this->assertSame(1000, $logger->stats()['dropped']);

        @trigger_error('host sentinel', E_USER_NOTICE);
        $logger->info(self::KEY, 'one more');
        $this->assertSame('host sentinel', error_get_last()['message'] ?? null, 'the host\'s last error is untouched');

        $logger->flush();
        $this->assertCount(2, $this->reported, 'flush() reports what the window held back');
        $this->assertSame(1000, $this->reported[1]->entryCount);
        $this->assertStringEndsWith('— 1000 lines lost since the last report, 1001 in total', $this->reported[1]->message);
        fclose($ours);
    }

    public function testTheReferenceCacheIsBoundedAndStillDerivesCorrectlyAfterClearing(): void
    {
        $logger = $this->logger();
        // Readable without setAccessible() since PHP 8.1.
        $cache = new \ReflectionProperty(Logger::class, 'partnerReferences');

        for ($i = 0; $i < 256; $i++) {
            $logger->info("key-{$i}", 'x');
        }
        $this->assertCount(256, $cache->getValue($logger));

        $logger->info('key-256', 'x');
        $this->assertSame(['key-256'], array_keys($cache->getValue($logger)), 'cleared at the bound, then refilled');
        $this->assertSame(
            PartnerReference::v1(self::TOKEN, 'key-256'),
            json_decode($this->lines[256], true)['partnerapi_partner_ref'],
        );
        $logger->info('key-0', 'again');
        $this->assertSame(
            PartnerReference::v1(self::TOKEN, 'key-0'),
            json_decode($this->lines[257], true)['partnerapi_partner_ref'],
        );
    }

    public function testDebugOutputNeverShowsTheTokenOrAnAppKey(): void
    {
        $key = 'sk_live_partner_secret_key';
        $logger = $this->logger();
        $logger->info($key, 'cached');
        $push = new Logger(self::TOKEN, httpClient: new RecordingTransport(static fn () => new Response(200)), options: [
            'flushOnShutdown' => false,
            'batchSize' => 0,
        ]);
        $push->info($key, 'buffered');

        foreach ([$logger, $logger->child(['partnerId' => 'p']), $push] as $subject) {
            ob_start();
            var_dump($subject);
            $dumped = (string) ob_get_clean() . print_r($subject, true);

            $this->assertStringNotContainsString(self::TOKEN, $dumped);
            $this->assertStringNotContainsString($key, $dumped);
            $this->assertStringNotContainsString(hash('sha256', $key), $dumped);
            $this->assertStringContainsString('[REDACTED]', $dumped);
        }
        $this->assertSame(1, $logger->__debugInfo()['cachedPartnerReferences']);

        // A logger whose constructor never ran (a subclass, a test double).
        $bare = (new \ReflectionClass(Logger::class))->newInstanceWithoutConstructor();
        $this->assertNull($bare->__debugInfo()['mode']);
    }

    public function testAProcessStdioSinkUnderPhpFpmWarnsOnceAtConstruction(): void
    {
        Logger::overrideShutdownHook(null, null, 'fpm-fcgi');

        $this->logger(['stdoutSink' => 'php://stdout']);
        $this->logger(['stdoutSink' => 'php://STDERR']);
        $this->logger(['stdoutSink' => $this->tempFile()]);
        $this->logger(['mode' => Logger::MODE_BUFFERED, 'stdoutSink' => 'php://stdout']);

        $this->assertSame(
            [LoggerErrorEvent::REASON_SINK_WARNING, LoggerErrorEvent::REASON_SINK_WARNING],
            array_map(static fn (LoggerErrorEvent $e) => $e->reason, $this->reported),
            'php://stdout and php://STDERR warn; a file or push mode do not',
        );
        $this->assertStringContainsString('catch_workers_output', $this->reported[0]->message);
        $this->assertSame(0, $this->reported[0]->entryCount);

        Logger::overrideShutdownHook(null, null, 'cli');
        $this->reported = [];
        $this->logger(['stdoutSink' => 'php://stdout']);
        $this->assertSame([], $this->reported, 'no warning outside FPM');
    }

    // ------------------------------------------------------- construction

    public function testOnlyLogSinksAreAccepted(): void
    {
        foreach (['php://output', 'php://input', 'php://memory', 'php://temp', 'php://temp/maxmemory:10', 'php://filter/read=string.toupper/resource=php://stdout', 'http://collector.example/lines', 'ftp://x/y', 'phar:///tmp/a.phar/x', 'compress.zlib:///tmp/x.gz', 'data:text/plain,x', 'data://text/plain,x'] as $sink) {
            try {
                new Logger(self::TOKEN, options: ['mode' => Logger::MODE_STDOUT, 'stdoutSink' => $sink]);
                $this->fail("accepted stdoutSink {$sink}");
            } catch (LoggerException $e) {
                $this->assertStringContainsString("stdoutSink {$sink} is not a log sink", $e->getMessage());
            }
        }
        try {
            new Logger(self::TOKEN, options: ['mode' => Logger::MODE_STDOUT, 'stdoutSink' => 'php://output']);
        } catch (LoggerException $e) {
            $this->assertStringContainsString('into the HTTP response', $e->getMessage());
        }

        foreach (['php://stdout', 'php://STDERR', 'php://fd/3', 'file:///var/log/x.jsonl', '/var/log/x.jsonl', 'logs/relative.jsonl'] as $sink) {
            $logger = new Logger(self::TOKEN, options: ['mode' => Logger::MODE_STDOUT, 'stdoutSink' => $sink]);
            $this->assertSame($sink, $logger->__debugInfo()['stdoutSink']);
        }
    }


    public function testConstructionRejectsAnUnusableSink(): void
    {
        foreach ([42, '', ['not', 'callable']] as $sink) {
            try {
                new Logger(self::TOKEN, options: ['mode' => Logger::MODE_STDOUT, 'stdoutSink' => $sink]);
                $this->fail('accepted stdoutSink ' . get_debug_type($sink));
            } catch (LoggerException $e) {
                $this->assertStringContainsString('stdoutSink', $e->getMessage());
            }
        }
    }

    public function testStdoutModeRefusesAnEmptyTenantToken(): void
    {
        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('Logger mode stdout needs tenantToken');
        new Logger('', options: ['mode' => Logger::MODE_STDOUT]);
    }

    public function testAnUnknownModeNamesStdoutAmongTheKnownOnes(): void
    {
        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('Unknown Logger mode: pipeline. Known modes: buffered, direct, stdout');
        new Logger(self::TOKEN, options: ['mode' => 'pipeline']);
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string, mixed> $options */
    private function logger(array $options = [], ?ClientInterface $http = null): Logger
    {
        return new Logger(
            tenantToken: self::TOKEN,
            baseUrl: 'https://ingest.test',
            httpClient: $http ?? new RecordingTransport(static fn () => new Response(200, [], '{}')),
            timestampProvider: static fn () => 1234567890000,
            options: $this->options($options),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function options(array $options): array
    {
        return array_replace([
            'mode' => Logger::MODE_STDOUT,
            'flushOnShutdown' => false,
            'stdoutSink' => function (string $line): void {
                $this->lines[] = $line;
            },
            'onError' => function (LoggerErrorEvent $event): void {
                $this->reported[] = $event;
            },
        ], $options);
    }

    /** A fresh directory, removed (with its files) at tearDown. */
    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/papi-5498-dir-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @return list<string> The `message` of every line in `$file`. */
    private function messagesIn(string $file): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($file)), static fn (string $l) => $l !== '');

        return array_values(array_map(static fn (string $line) => json_decode($line, true)['message'], $lines));
    }

    /** Root ignores directory permissions, so these tests would prove nothing. */
    private function skipIfPermissionsDoNotApply(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root: directory permissions are not enforced');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX directory permissions');
        }
    }

    private function tempFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'papi-5498-');
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * Runs `$code` in a fresh PHP process with the package autoloaded, so the
     * logger writes to that process's real standard streams.
     *
     * @return array{0: string, 1: string, 2: int} stdout, stderr, exit code
     */
    private function runPhp(string $code): array
    {
        $script = $this->tempFile();
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents($script, "<?php\nrequire {$autoload};\n{$code}\n");

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($process)];
    }
}

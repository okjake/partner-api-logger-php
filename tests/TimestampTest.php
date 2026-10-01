<?php

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\LoggerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entry timestamp, and the package's freedom from optional PHP
 * extensions (FLT-1306).
 *
 * Up to 2.0.0 the nanosecond timestamp was built with `bcmul()`, which needs
 * ext-bcmath — not declared in composer.json and absent from the official
 * `php:*-cli` images. On such a build every log call failed while building its
 * entry and was dropped, so nothing reached ingest. The timestamp is now built
 * with string operations, and for every value bcmath accepted — int, float or
 * numeric string — the wire format must not move by a byte.
 */
class TimestampTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array}> */
    private array $captured = [];

    /** @var list<LoggerErrorEvent> */
    private array $reported = [];

    private string|false $precision = false;

    protected function setUp(): void
    {
        $this->captured = [];
        $this->reported = [];
        // A float's timestamp is whatever `(string) $float` keeps, exactly as
        // it was under bcmath; pin PHP's default so the expectations hold.
        $this->precision = ini_get('precision');
        ini_set('precision', '14');
    }

    protected function tearDown(): void
    {
        if ($this->precision !== false) {
            ini_set('precision', $this->precision);
        }
    }

    /** @return array<string, array{int|float|string|\Stringable, string}> */
    public static function acceptedTimestamps(): array
    {
        return [
            // int — the default provider's type and the common case.
            'shared-fixture value' => [1234567890000, '1234567890000000000'],
            'representative now (2023-11-14)' => [1700000000000, '1700000000000000000'],
            // Every int must stay exact; a multiply would overflow to a float.
            'max signed 64-bit (PHP_INT_MAX)' => [PHP_INT_MAX, '9223372036854775807000000'],
            'JS Number.MAX_SAFE_INTEGER' => [9007199254740991, '9007199254740991000000'],
            'epoch' => [0, '0'],
            'before the epoch' => [-1, '-1000000'],
            'min signed 64-bit (PHP_INT_MIN)' => [PHP_INT_MIN, '-9223372036854775808000000'],

            // float — e.g. a provider returning `microtime(true) * 1000` with
            // no cast. Keeps the sub-ms digit the string cast keeps.
            'uncast microtime() * 1000' => [1790859907529.123, '1790859907529100000'],
            'half a millisecond' => [1234567890000.5, '1234567890000500000'],
            'whole float' => [1234567890000.0, '1234567890000000000'],
            'negative float' => [-1.5, '-1500000'],
            'float truncated toward zero past 1 ns' => [1.2345678, '1234567'],
            'negative float truncated toward zero' => [-1.2345678, '-1234567'],
            'negative zero float' => [-0.0, '0'],

            // string — bcmath's decimal grammar.
            'digit string' => ['1234567890000', '1234567890000000000'],
            'negative digit string' => ['-1', '-1000000'],
            'digit string past PHP_INT_MAX' => ['9223372036854775808', '9223372036854775808000000'],
            'leading zeros' => ['0012', '12000000'],
            'explicit plus sign' => ['+5', '5000000'],
            'negative zero string' => ['-0', '0'],
            'decimal string' => ['1234567890000.5', '1234567890000500000'],
            'string truncated toward zero past 1 ns' => ['1.2345678', '1234567'],
            'negative string truncated toward zero' => ['-1.2345678', '-1234567'],
            'sub-nanosecond string truncates to zero' => ['-0.0000009', '0'],
            'bare fraction' => ['.5', '500000'],
            'trailing dot' => ['5.', '5000000'],

            // Stringable — e.g. Brick\Math\BigInteger::of('1700000000000');
            // bcmath's string parameter cast it.
            'Stringable' => [new StringableTimestamp('1700000000000'), '1700000000000000000'],
        ];
    }

    #[DataProvider('acceptedTimestamps')]
    public function testTheEntryTimestampIsTheMillisecondsAsANanosecondString(int|float|string|\Stringable $ms, string $expectedNs): void
    {
        $logger = $this->logger(fn () => $ms);

        $logger->info('app-key', 'hello');
        $logger->flush();

        $this->assertSame([], $this->reported);
        $this->assertCount(1, $this->captured);
        $this->assertSame($expectedNs, $this->captured[0]['options']['json']['entries'][0]['timestamp']);
    }

    /**
     * Pins byte-identity with the `bcmul((string) $ms, '1000000')` this
     * replaced, wherever bcmath happens to be loaded (CI installs it today).
     * The literals above carry the same guarantee on builds without it.
     */
    #[DataProvider('acceptedTimestamps')]
    public function testTheTimestampIsByteIdenticalToTheBcmathProductItReplaced(int|float|string|\Stringable $ms, string $expectedNs): void
    {
        $this->requireBcmath();

        $this->assertSame(\bcmul((string) $ms, '1000000'), $expectedNs);
    }

    public function testAFloatKeepsWhatTheStringCastKeepsUnderThePrecisionIni(): void
    {
        ini_set('precision', '17');
        $logger = $this->logger(fn () => 1790859907529.123);

        $logger->info('app-key', 'hello');
        $logger->flush();

        $this->assertSame('1790859907529123000', $this->captured[0]['options']['json']['entries'][0]['timestamp']);
    }

    /** @return array<string, array{mixed, string, bool}> value, how the message names it, whether bcmath rejected it too */
    public static function rejectedTimestamps(): array
    {
        return [
            'NAN' => [NAN, 'float NAN', true],
            'INF' => [INF, 'float INF', true],
            '-INF' => [-INF, 'float -INF', true],
            'float from 1e15 up (exponent form)' => [1e15, 'float 1.0E+15', true],
            'float outside the int range' => [1e19, 'float 1.0E+19', true],
            'tiny float (exponent form)' => [9.0E-7, 'float 9.0E-7', true],
            'non-numeric string' => ['soon', 'non-numeric string', true],
            'exponent string' => ['1e3', 'non-numeric string', true],
            'hex string' => ['0x1A', 'non-numeric string', true],
            'leading whitespace' => [' 12', 'non-numeric string', true],
            'trailing newline' => ["12\n", 'non-numeric string', true],
            'array' => [[], 'array', true],
            'object' => [new \stdClass(), 'stdClass', true],
            'non-numeric Stringable' => [new StringableTimestamp('soon'), StringableTimestamp::class, true],
            // Stricter than bcmath, which read these as 0 (1 for true): a
            // 1970 timestamp is never what a provider meant.
            'empty string' => ['', 'non-numeric string', false],
            'sign only' => ['-', 'non-numeric string', false],
            'dot only' => ['.', 'non-numeric string', false],
            'plus only' => ['+', 'non-numeric string', false],
            'minus dot' => ['-.', 'non-numeric string', false],
            'plus dot' => ['+.', 'non-numeric string', false],
            'null' => [null, 'null', false],
            'true' => [true, 'bool', false],
            'false' => [false, 'bool', false],
        ];
    }

    #[DataProvider('rejectedTimestamps')]
    public function testAValueThatIsNotATimestampIsAnInvalidEntry(mixed $returned, string $got, bool $bcmathRejectedIt): void
    {
        $logger = $this->logger(fn () => $returned);

        $logger->info('app-key', 'hello');
        $logger->flush();

        $this->assertSame([], $this->captured);
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
        $this->assertSame(
            'Failed to send log: log entry could not be built: timestampProvider must return'
            . ' epoch milliseconds as an int, float or numeric string, got ' . $got,
            $this->reported[0]->message,
        );
        $this->assertSame(1, $logger->stats()['dropped']);
    }

    /**
     * Pins exactly where the replacement is stricter than bcmath, and that it
     * is nowhere looser: every other rejected shape made bcmath throw too.
     */
    #[DataProvider('rejectedTimestamps')]
    public function testRejectionsMatchBcmathExceptTheNamedTightenings(mixed $returned, string $got, bool $bcmathRejectedIt): void
    {
        $this->requireBcmath();

        try {
            \bcmul(@(string) $returned, '1000000');
            $bcmathThrew = false;
        } catch (\Throwable) {
            $bcmathThrew = true;
        }

        $this->assertSame($bcmathRejectedIt, $bcmathThrew);
    }

    public function testANonTimestampThrowsInDirectMode(): void
    {
        $logger = $this->logger(fn () => NAN, ['mode' => Logger::MODE_DIRECT]);

        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('as an int, float or numeric string, got float NAN');

        $logger->info('app-key', 'hello');
    }

    /**
     * The in-suite guard for FLT-1306. Running the suite on a stock
     * `php:8.1-cli` image is the honest proof, but CI installs bcmath, so a
     * reintroduced `bcmul()` would pass there and ship. This fails on any
     * build.
     */
    public function testSrcCallsNoBcmathFunction(): void
    {
        $calls = array_filter(
            $this->functionCallsInSrc(),
            static fn (string $call): bool => (bool) preg_match('/^bc[a-z]+$/i', explode(' ', $call)[0]),
        );

        $this->assertSame([], array_values($calls), 'src/ must not depend on ext-bcmath (FLT-1306)');
    }

    /**
     * Backs the readme's "no PHP extension beyond the defaults" claim: every
     * global function `src/` calls comes from an extension PHP 8.1 cannot be
     * built without, or is one of the two named, guarded exceptions.
     */
    public function testSrcCallsOnlyFunctionsFromAlwaysOnExtensions(): void
    {
        // Core, standard, date, json, pcre, SPL, Reflection, random and hash
        // are compiled into every PHP 8.1 build and cannot be disabled.
        $alwaysOn = ['core', 'standard', 'date', 'json', 'pcre', 'spl', 'reflection', 'random', 'hash'];
        $allowed = [
            // FPM-only; called behind function_exists().
            'fastcgi_finish_request',
            // Laravel's helper, only reachable from the Laravel service provider.
            'config_path',
        ];

        $offending = [];
        foreach ($this->functionCallsInSrc() as $call) {
            [$name] = explode(' ', $call);
            if (in_array($name, $allowed, true) || function_exists('PartnerApi\\Logger\\' . $name)) {
                continue;
            }
            $extension = function_exists($name)
                ? (new \ReflectionFunction($name))->getExtensionName()
                : 'not loaded here';
            if (!in_array(strtolower((string) $extension), $alwaysOn, true)) {
                $offending[] = "$call needs $extension";
            }
        }

        $this->assertSame([], $offending);
    }

    /**
     * Global function calls in `src/`, as "name (file:line)", found by
     * tokenising rather than grepping so comments and method calls are not
     * mistaken for calls.
     *
     * @return list<string>
     */
    private function functionCallsInSrc(): array
    {
        $notAFunctionCall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
        $src = dirname(__DIR__) . '/src';
        $calls = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                if (($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($previous) && in_array($previous[0], $notAFunctionCall, true)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($src) + 1);
                $calls[] = ltrim($token[1], '\\') . " (src/$relative:{$token[2]})";
            }
        }

        $this->assertNotEmpty($calls, 'the scan found no calls at all; it is broken, not passing');

        return $calls;
    }

    private function requireBcmath(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('ext-bcmath is not loaded; the literal expectations still apply.');
        }
    }

    private function logger(callable $timestampProvider, array $options = []): Logger
    {
        return new Logger(
            tenantToken: 'tenant-token',
            baseUrl: 'https://ingest.test',
            httpClient: $this->transport(),
            timestampProvider: $timestampProvider,
            options: array_merge([
                'flushOnShutdown' => false,
                'maxRetries' => 0,
                'onError' => function (LoggerErrorEvent $event): void {
                    $this->reported[] = $event;
                },
            ], $options),
        );
    }

    private function transport(): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) {
                $this->captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new Response(200, [], '{}');
            }
        );

        return $client;
    }
}

/** A provider value that is not a string but casts to one, like a Brick\Math number. */
final class StringableTimestamp implements \Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

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
 * with string concatenation, and the wire format must not move by a byte.
 */
class TimestampTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array}> */
    private array $captured = [];

    /** @var list<LoggerErrorEvent> */
    private array $reported = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $this->reported = [];
    }

    /** @return array<string, array{int, string}> */
    public static function millisecondTimestamps(): array
    {
        return [
            'shared-fixture value' => [1234567890000, '1234567890000000000'],
            'representative now (2023-11-14)' => [1700000000000, '1700000000000000000'],
            // Every int must stay exact; a multiply would overflow to a float.
            'max signed 64-bit (PHP_INT_MAX)' => [PHP_INT_MAX, '9223372036854775807000000'],
            'JS Number.MAX_SAFE_INTEGER' => [9007199254740991, '9007199254740991000000'],
            'epoch' => [0, '0'],
            'before the epoch' => [-1, '-1000000'],
            'min signed 64-bit (PHP_INT_MIN)' => [PHP_INT_MIN, '-9223372036854775808000000'],
        ];
    }

    #[DataProvider('millisecondTimestamps')]
    public function testTheEntryTimestampIsTheMillisecondsAsANanosecondString(int $ms, string $expectedNs): void
    {
        $logger = $this->logger(fn () => $ms);

        $logger->info('app-key', 'hello');
        $logger->flush();

        $this->assertSame([], $this->reported);
        $this->assertCount(1, $this->captured);
        $this->assertSame($expectedNs, $this->captured[0]['options']['json']['entries'][0]['timestamp']);
    }

    /**
     * Pins byte-identity with the `bcmul()` result this replaced, wherever
     * bcmath happens to be loaded (CI installs it today). The literals in the
     * test above carry the same guarantee on builds without it.
     */
    #[DataProvider('millisecondTimestamps')]
    public function testTheTimestampIsByteIdenticalToTheBcmathProductItReplaced(int $ms, string $expectedNs): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('ext-bcmath is not loaded; the literal expectations still apply.');
        }

        $this->assertSame(\bcmul((string) $ms, '1000000'), $expectedNs);
    }

    /** @return array<string, array{mixed, string}> */
    public static function nonIntegerTimestamps(): array
    {
        return [
            'float' => [1234567890000.0, 'float'],
            'numeric string' => ['1234567890000', 'string'],
            'null' => [null, 'null'],
        ];
    }

    /**
     * The provider's contract is `callable(): int` epoch milliseconds — the
     * default provider casts, and every documented and tested use passes an
     * int. Anything else is reported as an invalid entry naming the type,
     * never sent with a guessed timestamp.
     */
    #[DataProvider('nonIntegerTimestamps')]
    public function testANonIntegerTimestampIsAnInvalidEntry(mixed $returned, string $type): void
    {
        $logger = $this->logger(fn () => $returned);

        $logger->info('app-key', 'hello');
        $logger->flush();

        $this->assertSame([], $this->captured);
        $this->assertCount(1, $this->reported);
        $this->assertSame(LoggerErrorEvent::REASON_INVALID_ENTRY, $this->reported[0]->reason);
        $this->assertSame(
            'Failed to send log: log entry could not be built: timestampProvider must return'
            . ' integer epoch milliseconds, got ' . $type,
            $this->reported[0]->message,
        );
        $this->assertSame(1, $logger->stats()['dropped']);
    }

    public function testANonIntegerTimestampThrowsInDirectMode(): void
    {
        $logger = $this->logger(fn () => 1234567890000.0, ['mode' => Logger::MODE_DIRECT]);

        $this->expectException(LoggerException::class);
        $this->expectExceptionMessage('timestampProvider must return integer epoch milliseconds, got float');

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

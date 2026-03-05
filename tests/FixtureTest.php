<?php

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerException;
use PHPUnit\Framework\TestCase;

class FixtureTest extends TestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array<string, array{fixture: array}>
     */
    public static function fixtureProvider(): array
    {
        $fixturesDir = __DIR__ . '/../../logger-spec/fixtures';
        $cases = [];

        foreach (glob("{$fixturesDir}/*.json") as $file) {
            $fixture = json_decode(file_get_contents($file), true);
            $cases[$fixture['name']] = ['fixture' => $fixture];
        }

        return $cases;
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testFixture(array $fixture): void
    {
        $setup = $fixture['setup'];
        $action = $fixture['action'];
        $expect = $fixture['expect'];

        // Build mock HTTP client
        $capturedRequests = [];
        $mockError = $setup['mockHttpError'] ?? null;

        $httpClient = $this->createMock(ClientInterface::class);
        $requestExpectation = $httpClient->method('request');

        if ($mockError) {
            $requestExpectation->willThrowException(new \RuntimeException($mockError));
        } else {
            $requestExpectation->willReturn(
                new \GuzzleHttp\Psr7\Response(200, [], '{}')
            );
        }

        // Capture the request arguments
        $httpClient->method('request')->willReturnCallback(
            function (string $method, string $url, array $options) use (&$capturedRequests, $mockError) {
                $capturedRequests[] = [
                    'method' => $method,
                    'url' => $url,
                    'options' => $options,
                ];

                if ($mockError) {
                    throw new \RuntimeException($mockError);
                }

                return new \GuzzleHttp\Psr7\Response(200, [], '{}');
            }
        );

        // Create logger with mock timestamp
        $mockTimestamp = $setup['mockTimestamp'] ?? 1234567890000;
        $logger = new Logger(
            tenantToken: $setup['config']['tenantToken'],
            baseUrl: $setup['config']['baseUrl'] ?? null,
            httpClient: $httpClient,
            timestampProvider: fn () => $mockTimestamp,
        );

        // Apply context
        foreach ($setup['context'] ?? [] as $ctx) {
            $logger->setContext($ctx);
        }

        // Execute action
        $error = null;
        try {
            $this->executeAction($logger, $action);
        } catch (LoggerException $e) {
            $error = $e;
        }

        // Assert error expectation
        if (isset($expect['error'])) {
            $this->assertNotNull($error, "Expected error: {$expect['error']}");
            $this->assertSame($expect['error'], $error->getMessage());
        } else {
            if ($error) {
                throw $error;
            }
        }

        // Assert request expectation
        if (isset($expect['request']) && $expect['request'] !== null) {
            $this->assertCount(1, $capturedRequests, 'Expected exactly one HTTP request');

            $captured = $capturedRequests[0];
            $expectedRequest = $expect['request'];

            // Check URL
            $this->assertSame($expectedRequest['url'], $captured['url']);

            // Check headers
            $this->assertSame(
                $expectedRequest['headers'],
                $captured['options']['headers'],
            );

            // Check body
            $actualBody = $captured['options']['json'];
            $expectedBody = $expectedRequest['body'];

            // Parse line fields for comparison
            if (isset($actualBody['entries'])) {
                foreach ($actualBody['entries'] as &$entry) {
                    if (is_string($entry['line'])) {
                        $entry['line'] = json_decode($entry['line'], true);
                    }
                }
                unset($entry);
            }

            $this->assertDeepMatch($expectedBody, $actualBody, 'body');
        } elseif (array_key_exists('request', $expect) && $expect['request'] === null) {
            $this->assertEmpty($capturedRequests, 'Expected no HTTP request to be made');
        }
    }

    private function executeAction(Logger $logger, array $action): void
    {
        $method = $action['method'];
        $args = $action['args'];

        match ($method) {
            'info', 'warn', 'error', 'debug' => $logger->$method(
                $args['apiKey'],
                $args['message'],
                $args['data'] ?? [],
            ),
            'logRequest' => $logger->logRequest($args['apiKey'], $args['request']),
            'logResponse' => $logger->logResponse($args['apiKey'], $args['response']),
            'metric' => $logger->metric($args['apiKey'], $args['data']),
            'metrics' => $logger->metrics(
                $args['apiKey'],
                $args['slug'],
                $this->resolvePoints($args['points']),
            ),
            default => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }

    private function resolvePoints(mixed $points): array
    {
        if (is_string($points) && preg_match('/^<<generate:(\d+)>>$/', $points, $m)) {
            $count = (int) $m[1];
            return array_map(
                fn (int $i) => [
                    'timestamp' => '2024-01-01T00:00:00Z',
                    'value' => $i,
                    'period' => "point-{$i}",
                ],
                range(0, $count - 1),
            );
        }

        return $points;
    }

    private function assertDeepMatch(mixed $expected, mixed $actual, string $path): void
    {
        if ($expected === '<<uuid>>') {
            $this->assertIsString($actual, "{$path}: expected UUID string");
            $this->assertMatchesRegularExpression(self::UUID_PATTERN, $actual, "{$path}: expected UUID format");
            return;
        }

        if (is_array($expected) && is_array($actual)) {
            // Check if both are associative or both are sequential
            $expectedKeys = array_keys($expected);
            $actualKeys = array_keys($actual);

            $this->assertSame(
                count($expected),
                count($actual),
                "{$path}: array length mismatch — expected " . count($expected) . ", got " . count($actual)
                    . "\nExpected keys: " . json_encode($expectedKeys)
                    . "\nActual keys: " . json_encode($actualKeys),
            );

            foreach ($expected as $key => $value) {
                $this->assertArrayHasKey($key, $actual, "{$path}: missing key '{$key}'");
                $this->assertDeepMatch($value, $actual[$key], "{$path}.{$key}");
            }
            return;
        }

        $this->assertSame(
            $expected,
            $actual,
            "{$path}: value mismatch",
        );
    }
}

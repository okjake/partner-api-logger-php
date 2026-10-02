<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\ClientInterface;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\PartnerReference;
use PHPUnit\Framework\TestCase;

/**
 * Runs the pipeline-profile fixtures in `packages/logger-spec/fixtures/pipeline`
 * (spec § Pipeline profile, "Pipeline fixtures") against stdout mode
 * (PAPI-5498). {@see FixtureTest} globs only the top-level push fixtures.
 *
 * - `stdout-*.json`: one logger in `MODE_STDOUT` with a capturing callable
 *   sink; ONE request scope is opened, `setup.context` applied inside it and
 *   every action run inside it; then `flush()`. Each line is graded on the
 *   spec's five points: (a) the exact prefix bytes and (b) the envelope key
 *   order, both on the RAW text; (c) deep value equality of the parsed
 *   objects; (d) exactly one `"\n"`, at the end, per sink call; (e) neither
 *   the raw key, its SHA-256 (hex in either case, base64) nor the tenant token
 *   anywhere on the output.
 * - `partner-reference-vectors.json`: every derivation through the SDK's own
 *   {@see PartnerReference::v1()}, and the resolution cases against a map
 *   built from it.
 *
 * Expected and actual lines are decoded as OBJECTS (`json_decode(…, false)`):
 * an associative decode cannot tell `{}` from `[]`, which is precisely the
 * difference an empty header map must not lose.
 */
final class PipelineFixtureTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../logger-spec/fixtures/pipeline';

    private const PREFIX = '{"partnerapi_line":"1.6.0",';

    /** Envelope keys in the spec's order. */
    private const ENVELOPE_ORDER = [
        'partnerapi_line',
        'partnerapi_partner_ref',
        'partnerapi_timestamp',
        'partnerapi_level',
        'partnerapi_direction',
        'partnerapi_upstream_integration',
        'partnerapi_upstream_base_url',
    ];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function stdoutFixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES_DIR . '/stdout-*.json') ?: [] as $file) {
            $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $cases[basename($file, '.json')] = [$fixture];
        }

        return $cases;
    }

    public function testTheRunnerFindsTheStdoutFixtures(): void
    {
        // A wrong path would otherwise pass vacuously with zero cases.
        $this->assertGreaterThanOrEqual(15, count(self::stdoutFixtureProvider()));
    }

    /**
     * @dataProvider stdoutFixtureProvider
     * @param array<string, mixed> $fixture
     */
    public function testStdoutFixture(array $fixture): void
    {
        $setup = $fixture['setup'];
        $expect = $fixture['expect'];
        $this->assertSame('stdout', $setup['config']['delivery']);

        /** @var list<string> $writes */
        $writes = [];
        /** @var list<LoggerErrorEvent> $reported */
        $reported = [];

        // Log calls must not touch the network in this profile.
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->never())->method('request');

        $logger = new Logger(
            tenantToken: $setup['config']['tenantToken'],
            baseUrl: $setup['config']['baseUrl'] ?? null,
            httpClient: $http,
            timestampProvider: fn () => $setup['mockTimestamp'] ?? 1234567890000,
            options: [
                'mode' => Logger::MODE_STDOUT,
                'stdoutSink' => function (string $line) use (&$writes): void {
                    $writes[] = $line;
                },
                'flushOnShutdown' => false,
                'onError' => function (LoggerErrorEvent $event) use (&$reported): void {
                    $reported[] = $event;
                },
            ],
        );

        $logger->runWithContext([], function (Logger $scoped) use ($setup, $fixture): void {
            foreach ($setup['context'] ?? [] as $context) {
                $scoped->setContext($context);
            }
            foreach ($fixture['actions'] as $action) {
                $this->runAction($scoped, $action);
            }
        });
        $logger->flush();
        $this->assertSame(0, $logger->stats()['buffered']);

        if (isset($expect['error'])) {
            $this->assertNotEmpty($reported, "Expected error: {$expect['error']}");
            $this->assertSame($expect['error'], $reported[0]->message);
        } else {
            $this->assertSame([], array_map(static fn (LoggerErrorEvent $e) => $e->message, $reported));
        }

        $actual = implode('', $writes);
        $expectedLines = self::splitLines($expect['stdout']);

        // (d) one sink call per line, each ending in exactly one "\n".
        $this->assertCount(count($expectedLines), $writes, 'one sink write per expected line');
        foreach ($writes as $i => $write) {
            $this->assertStringEndsWith("\n", $write, "line {$i}: must end with \\n");
            $this->assertSame(1, substr_count($write, "\n"), "line {$i}: exactly one \\n");
        }

        foreach ($expectedLines as $i => $expectedLine) {
            $actualLine = substr($writes[$i], 0, -1);

            // (a) the exact prefix bytes, on the raw text.
            $this->assertStringStartsWith(self::PREFIX, $expectedLine, "fixture line {$i}: prefix");
            $this->assertSame(
                self::PREFIX,
                substr($actualLine, 0, strlen(self::PREFIX)),
                "line {$i}: byte-exact prefix",
            );

            // (b) envelope key order, on the raw text.
            $expectedKeys = self::topLevelKeys($expectedLine);
            $actualKeys = self::topLevelKeys($actualLine);
            $this->assertSame(
                array_values(array_unique($actualKeys)),
                $actualKeys,
                "line {$i}: a key is written twice",
            );
            $expectedEnvelope = self::envelopePrefix($expectedKeys);
            $this->assertSame(
                $expectedEnvelope,
                array_slice($actualKeys, 0, count($expectedEnvelope)),
                "line {$i}: envelope keys first, in order",
            );
            $this->assertSame(
                $expectedEnvelope,
                array_values(array_filter($actualKeys, static fn (string $k) => str_starts_with($k, 'partnerapi_'))),
                "line {$i}: no partnerapi_ key after the envelope",
            );
            $this->assertSame(
                $expectedEnvelope,
                array_values(array_intersect(self::ENVELOPE_ORDER, $expectedEnvelope)),
                "fixture line {$i}: envelope in spec order",
            );

            // (c) deep value equality, decoded as objects.
            $this->assertJsonValueEquals(
                json_decode($expectedLine, false, 512, JSON_THROW_ON_ERROR),
                json_decode($actualLine, false, 512, JSON_THROW_ON_ERROR),
                "line {$i}",
            );
        }

        // (e) never on a line.
        $this->assertSecretsAbsent($actual, $setup['config']['tenantToken'], $fixture['actions']);
        if ($expect['stdout'] === '') {
            $this->assertSame('', $actual, 'nothing written');
        }
    }

    public function testPartnerReferenceDerivationVectors(): void
    {
        $vectors = self::vectors();
        $this->assertSame('v1', $vectors['scheme']);
        $this->assertNotEmpty($vectors['derivation']);

        foreach ($vectors['derivation'] as $vector) {
            $what = $vector['description'];
            $this->assertSame($vector['utf8_hex'], bin2hex($vector['app_key']), "{$what}: key bytes");
            $this->assertSame($vector['message'], hash('sha256', $vector['app_key']), "{$what}: message");
            $this->assertSame(
                $vector['reference'],
                PartnerReference::v1($vector['tenant_token'], $vector['app_key']),
                "{$what}: reference",
            );
            $this->assertMatchesRegularExpression('/^v1:[0-9a-f]{64}\z/', $vector['reference']);
        }
    }

    /**
     * The SDK produces references; it does not resolve them. Building the
     * consumer's exact-string map from the SDK's own function and replaying
     * the resolution cases shows that what the SDK emits for an approved key
     * is what a consumer matches, and that no near-miss in the vectors is
     * something the SDK would ever produce.
     */
    public function testPartnerReferenceResolutionVectors(): void
    {
        $resolution = self::vectors()['resolution'];
        $map = [];
        foreach ($resolution['app_keys'] as $index => $appKey) {
            $map[PartnerReference::v1($resolution['tenant_token'], $appKey)] = $index;
        }

        foreach ($resolution['cases'] as $case) {
            $this->assertSame($case['matches'], $map[$case['reference']] ?? null, $case['description']);
        }
    }

    /**
     * @param array<string, mixed> $action
     */
    private function runAction(Logger $logger, array $action): void
    {
        $args = $action['args'];
        match ($action['method']) {
            'info', 'warn', 'error', 'debug' => $logger->{$action['method']}(
                $args['apiKey'],
                $args['message'],
                $args['data'] ?? [],
            ),
            'logRequest' => $logger->logRequest($args['apiKey'], $args['request']),
            'logResponse' => $logger->logResponse($args['apiKey'], $args['response']),
            'upstream' => $logger->upstream($args['call']),
            default => throw new \InvalidArgumentException("Unknown method: {$action['method']}"),
        };
    }

    /**
     * @return list<string> Lines without their "\n"; [] for "".
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        TestCase::assertStringEndsWith("\n", $text, 'fixture stdout ends with \n');

        return explode("\n", substr($text, 0, -1));
    }

    /**
     * The leading run of `partnerapi_*` keys.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private static function envelopePrefix(array $keys): array
    {
        $envelope = [];
        foreach ($keys as $key) {
            if (!str_starts_with($key, 'partnerapi_')) {
                break;
            }
            $envelope[] = $key;
        }

        return $envelope;
    }

    /**
     * The top-level keys of one JSON object, in the order they appear in the
     * TEXT, duplicates included. A decode would hide both order (JavaScript
     * reorders integer-like keys) and duplicates (the last one wins), and
     * those are what (b) grades.
     *
     * @return list<string>
     */
    private static function topLevelKeys(string $json): array
    {
        $keys = [];
        $depth = 0;
        $length = strlen($json);
        $expectKey = false;

        for ($i = 0; $i < $length; $i++) {
            $c = $json[$i];
            if ($c === '"') {
                $start = $i;
                for ($i++; $i < $length && $json[$i] !== '"'; $i++) {
                    if ($json[$i] === '\\') {
                        $i++;
                    }
                }
                if ($depth === 1 && $expectKey) {
                    $keys[] = json_decode(substr($json, $start, $i - $start + 1), false, 512, JSON_THROW_ON_ERROR);
                    $expectKey = false;
                }
                continue;
            }
            if ($c === '{' || $c === '[') {
                $depth++;
                $expectKey = $depth === 1 && $c === '{';
            } elseif ($c === '}' || $c === ']') {
                $depth--;
            } elseif ($c === ',' && $depth === 1) {
                $expectKey = true;
            }
        }

        return $keys;
    }

    /**
     * JSON value equality: objects by key set (line-field order is not part of
     * the contract), lists by position, numbers by value (`150` and `150.0`,
     * `1e+21` and `1.0e+21` are equal), everything else strictly.
     */
    private function assertJsonValueEquals(mixed $expected, mixed $actual, string $path): void
    {
        if ($expected instanceof \stdClass) {
            $this->assertInstanceOf(\stdClass::class, $actual, "{$path}: expected an object, got " . get_debug_type($actual));
            $expectedKeys = array_keys(get_object_vars($expected));
            $actualKeys = array_keys(get_object_vars($actual));
            sort($expectedKeys);
            sort($actualKeys);
            $this->assertSame($expectedKeys, $actualKeys, "{$path}: keys");
            foreach (get_object_vars($expected) as $key => $value) {
                $this->assertJsonValueEquals($value, $actual->{$key}, "{$path}.{$key}");
            }
            return;
        }

        if (is_array($expected)) {
            $this->assertIsArray($actual, "{$path}: expected a list, got " . get_debug_type($actual));
            $this->assertCount(count($expected), $actual, "{$path}: length");
            foreach ($expected as $i => $value) {
                $this->assertJsonValueEquals($value, $actual[$i], "{$path}[{$i}]");
            }
            return;
        }

        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            if (is_int($expected) && is_int($actual)) {
                $this->assertSame($expected, $actual, "{$path}: number");
            } else {
                $this->assertSame((float) $expected, (float) $actual, "{$path}: number");
            }
            return;
        }

        $this->assertSame($expected, $actual, "{$path}: value");
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function assertSecretsAbsent(string $output, string $tenantToken, array $actions): void
    {
        $this->assertStringNotContainsString($tenantToken, $output, 'tenant token on a line');

        foreach ($actions as $action) {
            $key = $action['args']['apiKey'] ?? '';
            if ($key === '') {
                continue;
            }
            $digest = hash('sha256', $key, true);
            $forms = [
                'raw key' => $key,
                'JSON-escaped key' => substr((string) json_encode($key), 1, -1),
                'SHA-256 hex' => bin2hex($digest),
                'SHA-256 HEX' => strtoupper(bin2hex($digest)),
                'SHA-256 base64' => base64_encode($digest),
                'SHA-256 base64url' => rtrim(strtr(base64_encode($digest), '+/', '-_'), '='),
            ];
            foreach ($forms as $what => $form) {
                $this->assertStringNotContainsString($form, $output, "{$what} on a line");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function vectors(): array
    {
        return json_decode(
            (string) file_get_contents(self::FIXTURES_DIR . '/partner-reference-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}

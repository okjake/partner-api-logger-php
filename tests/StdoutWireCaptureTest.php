<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\PartnerReference;
use PHPUnit\Framework\TestCase;

/**
 * `tests/fixtures/php-sdk-stdout.papi-5498.json` is a committed capture of
 * what stdout mode writes, kept for ingest's OTLP round-trip spec (PAPI-5495).
 * A capture that no longer matches the SDK would let that spec pass against
 * lines the SDK stopped writing, so this re-runs the capture script and
 * requires the same bytes. Regenerate with `scripts/capture-stdout-wire.php`.
 */
final class StdoutWireCaptureTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/php-sdk-stdout.papi-5498.json';

    public function testTheCommittedCaptureIsWhatTheSdkWritesToday(): void
    {
        $committed = self::decode((string) file_get_contents(self::FIXTURE));

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/scripts/capture-stdout-wire.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        $fresh = self::decode($output);
        $this->assertSame(
            $committed['stdout'],
            $fresh['stdout'],
            'stdout mode writes something else now: regenerate the capture (see scripts/capture-stdout-wire.php)',
        );
        $this->assertSame(hash('sha256', $committed['stdout']), $committed['sha256']);
        $this->assertSame(strlen($committed['stdout']), $committed['bytes']);
        $this->assertSame(substr_count($committed['stdout'], "\n"), $committed['lineCount']);
        foreach ($committed['references'] as $appKey => $reference) {
            $this->assertSame(PartnerReference::v1($committed['tenantToken'], (string) $appKey), $reference);
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

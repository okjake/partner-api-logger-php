<?php

/**
 * Captures the REAL PHP SDK's stdout-mode output (PAPI-5498) for
 * `packages/logger-php/tests/fixtures/php-sdk-stdout.papi-5498.json`: the
 * lines a customer's collector would read from the process and forward to
 * ingest's OTLP endpoint (PAPI-5495) as string bodies. CI has no PHP runtime
 * on the ingest side, so the capture is committed, as the push-path captures
 * in `apps/ingest/src/fixtures/php-sdk-*.json` are.
 *
 * Nothing is mocked: the script re-runs itself as a child PHP process whose
 * Logger uses the DEFAULT sink, `php://stdout`, and captures that process's
 * real standard output through a pipe. The child serves three requests on one
 * long-lived logger, two partners between them:
 *
 * 1. an inbound request/response pair with redacted headers (by name, and a
 *    header whose value is the app key), bodies, an info line carrying an
 *    integer-like data key, and an upstream trail of two calls (a query
 *    string to strip, and a network error);
 * 2. an outbound call by the second partner, with direction and upstream
 *    attribution in the envelope;
 * 3. a rejected request whose response has no headers and no body.
 *
 * Upstream calls are recorded with `upstream()` so the capture is
 * deterministic; the Guzzle middleware feeds the same trail and has its own
 * wire capture (`capture-upstream-trail-wire.php`).
 *
 * Regenerate from packages/logger-php (no extension beyond json is needed):
 *
 *   CAPTURED_AT="$(git rev-parse --short HEAD)" php scripts/capture-stdout-wire.php \
 *     > tests/fixtures/php-sdk-stdout.papi-5498.json
 *
 * `StdoutWireCaptureTest` fails when the committed `stdout` no longer matches
 * what the SDK writes.
 */

declare(strict_types=1);

use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\PartnerReference;

require __DIR__ . '/../vendor/autoload.php';

const TENANT_TOKEN = 'tenant_live_test123';
// The first two keys of packages/logger-spec/fixtures/pipeline/partner-reference-vectors.json.
const APP_KEYS = ['test-api-key', 'second-partner-key'];
const PREFIX = '{"partnerapi_line":"1.6.0",';

if (($argv[1] ?? null) === '--emit') {
    emit();
    exit(0);
}

$child = proc_open(
    [PHP_BINARY, __FILE__, '--emit'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($child);

$problems = [];
if ($exit !== 0 || $stderr !== '') {
    $problems[] = "child exited {$exit}: {$stderr}";
}
$lines = $stdout === '' ? [] : explode("\n", substr($stdout, 0, -1));
if ($lines === [] || !str_ends_with($stdout, "\n")) {
    $problems[] = 'no complete lines captured';
}
foreach ($lines as $i => $line) {
    if (!str_starts_with($line, PREFIX)) {
        $problems[] = "line {$i} lacks the prefix";
    }
    json_decode($line, false, 512, JSON_THROW_ON_ERROR);
}
foreach ([TENANT_TOKEN, ...APP_KEYS, ...array_map(static fn (string $k) => hash('sha256', $k), APP_KEYS)] as $secret) {
    if (str_contains($stdout, $secret)) {
        $problems[] = 'a secret reached the output';
    }
}
if ($problems !== []) {
    fwrite(STDERR, "capture failed\n" . implode("\n", $problems) . "\n");
    exit(1);
}

$references = [];
foreach (APP_KEYS as $key) {
    $references[$key] = PartnerReference::v1(TENANT_TOKEN, $key);
}

echo json_encode([
    '_comment' => 'PAPI-5498: stdout-mode lines exactly as the REAL PHP SDK (packages/logger-php) wrote them to its own standard output (the default sink), captured through a pipe: three requests on one long-lived logger for two partners — an inbound request/response pair with redacted headers, bodies, an integer-like data key and a two-call upstream trail; an outbound line with direction and upstream attribution; a rejection whose response has no headers or body. `stdout` is the raw text (one line per log call, each ending in one \n), checked by sha256. A collector forwards each line as an OTLP string body under x-tenant-token = tenantToken; `references` maps each app key to the partner reference its lines carry. Regenerate with packages/logger-php/scripts/capture-stdout-wire.php (see its docblock).',
    'capturedWith' => sprintf('php %s, packages/logger-php @ %s', PHP_VERSION, getenv('CAPTURED_AT') ?: 'unknown'),
    'tenantToken' => TENANT_TOKEN,
    'references' => $references,
    'lineCount' => count($lines),
    'sha256' => hash('sha256', $stdout),
    'bytes' => strlen($stdout),
    'stdout' => $stdout,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

/** The child: one long-lived logger in stdout mode with its default sink. */
function emit(): void
{
    $logger = new Logger(
        tenantToken: TENANT_TOKEN,
        timestampProvider: static fn () => 1790000000000,
        options: [
            'mode' => Logger::MODE_STDOUT,
            'onError' => static function (LoggerErrorEvent $event): void {
                fwrite(STDERR, "{$event->reason}: {$event->message}\n");
            },
        ],
    );
    [$first, $second] = APP_KEYS;

    // 1. Inbound request/response with a trail.
    $logger->runWithContext(['partnerId' => 'partner-php-pipeline'], static function (Logger $scoped) use ($first): void {
        $scoped->logRequest($first, [
            'method' => 'POST',
            'path' => '/v1/bookings?expand=guest',
            'headers' => [
                'content-type' => 'application/json',
                'authorization' => 'Bearer partner-session',
                'x-partner-key' => $first,
                'x-correlation-id' => 'corr-php-pipe-1',
                'x-request-id' => 'req-php-pipe-1',
            ],
            'body' => ['guestName' => 'Ana', 'nights' => 2, 'note' => 'café 名'],
        ]);
        $scoped->upstream([
            'name' => 'stripe',
            'method' => 'POST',
            'url' => 'https://api.stripe.com/v1/charges?expand=customer',
            'status' => 200,
            'durationMs' => 120,
            'requestId' => 'req_stripe_1',
        ]);
        $scoped->upstream([
            'name' => 'channel-manager',
            'method' => 'GET',
            'url' => 'https://cm.example.com/availability',
            'durationMs' => 3000,
            'errorCode' => 'ETIMEDOUT',
            'message' => 'timed out',
            'attempt' => 1,
        ]);
        $scoped->info($first, 'Booking validated', ['booking_id' => 'bk_1', '2026' => 'season']);
        $scoped->logResponse($first, [
            'statusCode' => 201,
            'headers' => ['content-type' => 'application/json', 'set-cookie' => 's=1'],
            'body' => ['id' => 'bk_1', 'status' => 'confirmed'],
            'duration' => 3150,
            'correlationId' => 'corr-php-pipe-1',
        ]);
    });

    // 2. The second partner's outbound call.
    $logger->runWithContext(
        [
            'partnerId' => 'partner-php-pipeline-2',
            'direction' => 'outbound',
            'upstreamIntegration' => 'channel-manager',
            'upstreamBaseUrl' => 'https://cm.example.com',
            'correlationId' => 'corr-php-pipe-2',
        ],
        static function (Logger $scoped) use ($second): void {
            $scoped->warn($second, 'Availability sync retried', ['attempt' => 2, 'property_id' => 'prop_9']);
        },
    );

    // 3. A rejection; the response carries no headers and no body.
    $logger->runWithContext(['partnerId' => 'partner-php-pipeline'], static function (Logger $scoped) use ($first): void {
        $scoped->logRequest($first, [
            'method' => 'PUT',
            'path' => '/v1/bookings/bk_1',
            'headers' => ['x-correlation-id' => 'corr-php-pipe-3'],
            'body' => ['guestName' => ''],
        ]);
        $scoped->error($first, 'Booking rejected', [
            'error_code' => 'VALIDATION_ERROR',
            'error_field' => 'guestName',
            'status_code' => 422,
        ]);
        $scoped->logResponse($first, ['statusCode' => 422, 'duration' => 8, 'correlationId' => 'corr-php-pipe-3']);
    });

    $logger->flush();
}

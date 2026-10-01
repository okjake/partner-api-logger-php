<?php

/**
 * Captures the REAL PHP SDK's `POST /logs` bodies for responses carrying an
 * upstream trail (FLT-1301), for
 * `apps/ingest/src/fixtures/php-sdk-upstream-trail.flt-1301.json`, which
 * `apps/ingest/src/upstream-trail.php-sdk-round-trip.spec.ts` replays through
 * ingest. CI has no PHP runtime, so the capture is committed.
 *
 * Nothing is mocked on the PHP side: a real Logger with its default Guzzle
 * client posts to a local `php -S` capture server (scripts/capture-server.php),
 * and the upstream calls go over real HTTP through a Guzzle client carrying
 * `upstreamMiddleware()` — one to a port nothing listens on, for a real cURL
 * connect failure.
 *
 * Regenerate, from the repo root (needs ext-bcmath for the timestamp):
 *
 *   docker run --rm -v "$PWD:/repo" -w /repo/packages/logger-php \
 *     -e CAPTURED_AT="$(git rev-parse --short HEAD)" papi-php:8.1 \
 *     sh -c 'composer install -q && php scripts/capture-upstream-trail-wire.php' \
 *     > apps/ingest/src/fixtures/php-sdk-upstream-trail.flt-1301.json
 *
 * (`papi-php:8.1` is php:8.1-cli plus ext-bcmath and composer; any PHP 8.1+
 * CLI with bcmath and curl does.) Durations are real, so a regenerated
 * capture differs in `durationMs` values; the spec asserts shapes and
 * round-trip equality, never those numbers.
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;

require __DIR__ . '/../vendor/autoload.php';

const PORT = 8765;
// The vendor calls use `localhost`, not an IP: ingest's PII sanitiser
// redacts IP addresses inside `message`, and this capture is meant to
// round-trip unchanged.
const VENDOR = 'http://localhost:' . PORT;

$captureFile = tempnam(sys_get_temp_dir(), 'papi-capture-');
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . PORT, __DIR__ . '/capture-server.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['CAPTURE_FILE' => $captureFile] + getenv(),
);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', PORT) === false; $i++) {
    usleep(100000);
}

$errors = [];
$logger = new Logger(
    tenantToken: 'tenant-token',
    baseUrl: 'http://127.0.0.1:' . PORT,
    timestampProvider: fn () => 1790000000000,
    options: [
        'batchSize' => 0,
        'maxRetries' => 0,
        'flushOnShutdown' => false,
        'onError' => function (LoggerErrorEvent $event) use (&$errors): void {
            $errors[] = $event->message;
        },
    ],
);

// Built once, as a long-running worker would, and shared by every request.
$stack = HandlerStack::create();
$stack->push($logger->upstreamMiddleware('stripe'));
$stripe = new Client(['handler' => $stack, 'timeout' => 5]);

$apiKey = 'app-key';
$exchange = function (string $correlationId, callable $work) use ($logger, $apiKey): void {
    $logger->runWithContext(['partnerId' => 'partner-php-trail'], function () use ($logger, $apiKey, $correlationId, $work): void {
        $logger->logRequest($apiKey, [
            'method' => 'POST',
            'path' => '/v1/orders',
            'headers' => ['x-correlation-id' => $correlationId],
        ]);
        $work();
        $logger->logResponse($apiKey, [
            'statusCode' => 502,
            'duration' => 900,
            'correlationId' => $correlationId,
        ]);
    });
};

// 1. Every shape: success with a vendor request id and a query string to
//    strip, an HTTP error raised by `http_errors`, a real connect failure,
//    and a hand-recorded call with every optional field.
$exchange('corr-php-full', function () use ($stripe, $logger): void {
    $stripe->get(VENDOR . '/v1/customers?email=jo@example.com');
    try {
        $stripe->post(VENDOR . '/v1/charges', ['form_params' => ['amount' => 100]]);
    } catch (ClientException) {
    }
    try {
        $stripe->get('http://localhost:1/v1/down?token=sk_live_9');
    } catch (ConnectException) {
    }
    $logger->upstream([
        'name' => 'pricing',
        'method' => 'M-SEARCH',
        'url' => 'https://pricing.internal/quote',
        'status' => 503,
        'durationMs' => 41.5,
        'errorCode' => 'upstream_unavailable',
        'message' => 'pricing service unavailable',
        'attempt' => 2,
    ]);
});

// 2. The count cap: 23 calls, the oldest 3 dropped and marked.
$exchange('corr-php-capped', function () use ($logger): void {
    for ($i = 0; $i < 23; $i++) {
        $logger->upstream([
            'name' => "call-{$i}",
            'method' => 'GET',
            'url' => "https://vendor.test/items/{$i}",
            'status' => 200,
            'durationMs' => $i,
        ]);
    }
});

// 3. Handed off from nested scopes, the nested drops counted.
$exchange('corr-php-nested', function () use ($logger): void {
    $logger->upstream([
        'name' => 'outer',
        'method' => 'GET',
        'url' => 'https://vendor.test/outer',
        'status' => 200,
        'durationMs' => 1,
    ]);
    $logger->runWithContext([], function () use ($logger): void {
        for ($i = 0; $i < 22; $i++) {
            $logger->child()->upstream([
                'name' => "nested-{$i}",
                'method' => 'POST',
                'url' => "https://vendor.test/nested/{$i}",
                'status' => 201,
                'durationMs' => $i,
            ]);
        }
    });
});

// 4. The byte cap.
$exchange('corr-php-bytes', function () use ($logger): void {
    for ($i = 0; $i < 12; $i++) {
        $logger->upstream([
            'name' => "call-{$i}",
            'method' => 'POST',
            'url' => "https://vendor.test/batch/{$i}",
            'status' => 500,
            'durationMs' => 10,
            'message' => substr(str_repeat("upstream said no, attempt {$i} ", 40), 0, 1000),
        ]);
    }
});

// 5. No calls: no `upstream` key at all.
$exchange('corr-php-none', function (): void {
});

$logger->flush();
proc_terminate($server);
proc_close($server);

$posts = [];
foreach (file($captureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
    $post = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
    $posts[] = [
        'headers' => $post['headers'],
        'sha256' => hash('sha256', $post['raw']),
        'bytes' => strlen($post['raw']),
        'raw' => $post['raw'],
    ];
}
unlink($captureFile);

if ($errors !== [] || $posts === []) {
    fwrite(STDERR, "capture failed\n" . json_encode(['errors' => $errors, 'posts' => count($posts)], JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

echo json_encode([
    '_comment' => 'FLT-1301: POST /logs bodies exactly as the REAL PHP SDK (packages/logger-php) sent them to a capture server: five request scopes on one long-lived logger, whose response lines carry an upstream trail — every call shape through upstreamMiddleware() over real HTTP plus upstream(), the 20-call cap, a hand-off from nested scopes, the 8 KB cap, and a response with no calls. Each raw is checked by sha256. Regenerate with packages/logger-php/scripts/capture-upstream-trail-wire.php (see its docblock).',
    'capturedWith' => sprintf(
        'php %s, guzzle %s, packages/logger-php @ %s',
        PHP_VERSION,
        \Composer\InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'),
        getenv('CAPTURED_AT') ?: 'unknown',
    ),
    'posts' => $posts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

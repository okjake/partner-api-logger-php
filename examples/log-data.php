<?php

/**
 * pump.php — Exercise the Partner API PHP logger against a local ingest service.
 *
 * Sends a realistic mix of structured logs (info, warn, error, debug),
 * request/response pairs, single metrics, and batch metrics so you can
 * verify everything looks correct in the UI.
 *
 * Usage:
 *   php examples/pump.php --tenant-token=<token> --api-key=<key> [options]
 *
 * Options:
 *   --tenant-token=TOKEN   (required) Tenant token for the ingest service
 *   --api-key=KEY          (required) API key sent as x-api-key header
 *   --base-url=URL         Ingest service base URL (default: https://ingest.partnerapi.com)
 *   --partner-id=ID        Optional partner ID to set in context
 *   --help                 Show this help message
 *
 * Environment variables (used as fallbacks):
 *   PUMP_TENANT_TOKEN, PUMP_API_KEY, PUMP_BASE_URL, PUMP_PARTNER_ID
 */

// Handle --help before autoload so it works even without vendor/ installed.
if (in_array('--help', $argv, true)) {
    echo <<<'HELP'
Usage: php examples/pump.php --tenant-token=<token> --api-key=<key> [options]

Options:
  --tenant-token=TOKEN   (required) Tenant token for the ingest service
  --api-key=KEY          (required) API key sent as x-api-key header
  --base-url=URL         Ingest service base URL (default: https://ingest.partnerapi.com)
  --partner-id=ID        Optional partner ID to set in context
  --help                 Show this help message

Environment variable fallbacks:
  PUMP_TENANT_TOKEN, PUMP_API_KEY, PUMP_BASE_URL, PUMP_PARTNER_ID

HELP;
    exit(0);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run 'composer install' first.\n");
    exit(1);
}
require_once $autoload;

use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerException;

// ---------------------------------------------------------------------------
// CLI helpers
// ---------------------------------------------------------------------------

function color(string $text, string $color): string
{
    $codes = [
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'red'    => "\033[31m",
        'cyan'   => "\033[36m",
        'dim'    => "\033[2m",
        'bold'   => "\033[1m",
        'reset'  => "\033[0m",
    ];
    return ($codes[$color] ?? '') . $text . $codes['reset'];
}

function step(string $label): void
{
    echo color('  >> ', 'dim') . $label . ' ... ';
}

function ok(): void
{
    echo color('OK', 'green') . "\n";
}

function fail(string $message): void
{
    echo color('FAIL', 'red') . "\n";
    echo color("     {$message}", 'red') . "\n";
}

function heading(string $text): void
{
    echo "\n" . color($text, 'bold') . "\n";
}

function parseArgs(array $argv): array
{
    $opts = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m)) {
            $opts[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
            $opts[$m[1]] = true;
        }
    }
    return $opts;
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

$opts = parseArgs($argv);

$tenantToken = $opts['tenant-token'] ?? getenv('PUMP_TENANT_TOKEN') ?: null;
$apiKey      = $opts['api-key']      ?? getenv('PUMP_API_KEY')      ?: null;
$baseUrl     = $opts['base-url']     ?? getenv('PUMP_BASE_URL')     ?: 'https://ingest.partnerapi.com';
$partnerId   = $opts['partner-id']   ?? getenv('PUMP_PARTNER_ID')   ?: null;

if (!$tenantToken || !$apiKey) {
    fwrite(STDERR, color('Error: --tenant-token and --api-key are required.', 'red') . "\n");
    fwrite(STDERR, "Run with --help for usage.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

echo color("\n  Partner API Logger — pump script", 'bold') . "\n";
echo color("  ================================", 'dim') . "\n";
echo color("  Base URL:      ", 'dim') . $baseUrl . "\n";
echo color("  Tenant token:  ", 'dim') . substr($tenantToken, 0, 8) . '...' . "\n";
echo color("  API key:       ", 'dim') . substr($apiKey, 0, 8) . '...' . "\n";
if ($partnerId) {
    echo color("  Partner ID:    ", 'dim') . $partnerId . "\n";
}
echo "\n";

// Direct mode on purpose. Since 2.0.0 the default is buffered — log calls
// queue and drain after the response — which would make every step below
// print OK and surface the real failures, if any, at the very end. This pump
// exists to tell you per call whether ingest accepted it, so it opts into the
// pre-2.0 synchronous profile. Application code should NOT do this.
$logger = new Logger($tenantToken, $baseUrl, options: ['mode' => Logger::MODE_DIRECT]);

if ($partnerId) {
    $logger->setContext(['partnerId' => $partnerId]);
    echo color("  Context set with partnerId={$partnerId}", 'dim') . "\n";
}

$delay = 150; // ms between sends

function pause(int $ms = 150): void
{
    usleep($ms * 1000);
}

// ---------------------------------------------------------------------------
// 1. Structured logs — info, warn, error, debug
// ---------------------------------------------------------------------------

heading('1. Structured logs');

$logs = [
    ['info',  'User signed up',              ['userId' => 'usr_8f3a21', 'plan' => 'pro', 'source' => 'referral']],
    ['info',  'Payment processed',           ['orderId' => 'ord_44c1', 'amount' => 129.99, 'currency' => 'USD']],
    ['warn',  'Rate limit approaching',      ['endpoint' => '/api/v2/listings', 'current' => 847, 'limit' => 1000, 'window' => '1m']],
    ['error', 'Payment gateway timeout',     ['provider' => 'stripe', 'timeout_ms' => 30000, 'orderId' => 'ord_99b2', 'retry' => 2]],
    ['error', 'Webhook delivery failed',     ['webhookId' => 'wh_a12f', 'url' => 'https://partner.example.com/hooks', 'statusCode' => 502]],
    ['debug', 'Cache miss for listing data', ['cacheKey' => 'listing:4821', 'region' => 'us-east-1', 'ttl' => 300]],
    ['debug', 'Query plan slow',             ['query' => 'SELECT * FROM bookings WHERE ...', 'duration_ms' => 1842, 'rows' => 48210]],
];

foreach ($logs as [$level, $message, $data]) {
    step("{$level}: {$message}");
    try {
        $logger->{$level}($apiKey, $message, $data);
        ok();
    } catch (LoggerException $e) {
        fail($e->getMessage());
    }
    pause($delay);
}

// ---------------------------------------------------------------------------
// 2. Request / response pairs
// ---------------------------------------------------------------------------

heading('2. Request / response pairs');

$requests = [
    [
        'req' => ['method' => 'GET', 'path' => '/api/v2/listings/4821', 'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer tok_xyz']],
        'res' => ['statusCode' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => ['id' => 4821, 'title' => 'Downtown Loft'], 'duration' => 42],
    ],
    [
        'req' => ['method' => 'POST', 'path' => '/api/v2/bookings', 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer tok_xyz'], 'body' => ['listingId' => 4821, 'guestId' => 'usr_8f3a21', 'checkIn' => '2026-04-10', 'checkOut' => '2026-04-14']],
        'res' => ['statusCode' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => ['bookingId' => 'bk_7713'], 'duration' => 187],
    ],
    [
        'req' => ['method' => 'DELETE', 'path' => '/api/v2/listings/9999', 'headers' => ['Authorization' => 'Bearer tok_expired']],
        'res' => ['statusCode' => 403, 'headers' => ['Content-Type' => 'application/json'], 'body' => ['error' => 'Forbidden'], 'duration' => 8],
    ],
];

foreach ($requests as $pair) {
    $method = $pair['req']['method'];
    $path = $pair['req']['path'];
    $status = $pair['res']['statusCode'];

    step("request  {$method} {$path}");
    try {
        $correlationId = $logger->logRequest($apiKey, $pair['req']);
        ok();
    } catch (LoggerException $e) {
        fail($e->getMessage());
        continue;
    }
    pause(50);

    step("response {$status} ({$pair['res']['duration']}ms)");
    try {
        $logger->logResponse($apiKey, array_merge($pair['res'], ['correlationId' => $correlationId]));
        ok();
    } catch (LoggerException $e) {
        fail($e->getMessage());
    }
    pause($delay);
}

// ---------------------------------------------------------------------------
// 3. Single metric calls
// ---------------------------------------------------------------------------

heading('3. Single metrics');

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$singles = [
    ['slug' => 'revenue',       'value' => 12499.50, 'period' => 'daily',  'metadata' => ['currency' => 'USD']],
    ['slug' => 'active-users',  'value' => 342,      'period' => 'daily'],
    ['slug' => 'response-time', 'value' => 87.3,     'period' => 'hourly', 'metadata' => ['unit' => 'ms', 'p' => 'p95']],
];

foreach ($singles as $m) {
    step("metric: {$m['slug']} = {$m['value']} ({$m['period']})");
    try {
        $point = [
            'slug'      => $m['slug'],
            'timestamp' => $now->format('c'),
            'value'     => $m['value'],
            'period'    => $m['period'],
        ];
        if (isset($m['metadata'])) {
            $point['metadata'] = $m['metadata'];
        }
        $logger->metric($apiKey, $point);
        ok();
    } catch (LoggerException $e) {
        fail($e->getMessage());
    }
    pause($delay);
}

// ---------------------------------------------------------------------------
// 4. Batch metrics — daily revenue for the last 7 days
// ---------------------------------------------------------------------------

heading('4. Batch metrics (daily revenue, last 7 days)');

step('revenue batch (7 points)');
try {
    $points = [];
    $baseRevenue = 10000;
    for ($i = 6; $i >= 0; $i--) {
        $day = $now->modify("-{$i} days")->setTime(0, 0, 0);
        $jitter = (mt_rand(-2000, 3000));
        $points[] = [
            'timestamp' => $day->format('c'),
            'value'     => $baseRevenue + $jitter + ($i * 200),
            'period'    => 'daily',
            'metadata'  => ['currency' => 'USD'],
        ];
    }
    $logger->metrics($apiKey, 'revenue', $points);
    ok();
} catch (LoggerException $e) {
    fail($e->getMessage());
}
pause($delay);

// ---------------------------------------------------------------------------
// 5. Batch metrics with series — revenue by region, last 7 days
// ---------------------------------------------------------------------------

heading('5. Multi-series batch metrics (revenue by region, last 7 days)');

$regions = ['us-east' => 6000, 'us-west' => 3500, 'eu-west' => 2800];

foreach ($regions as $region => $base) {
    step("revenue/{$region} batch (7 points)");
    try {
        $points = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->modify("-{$i} days")->setTime(0, 0, 0);
            $jitter = mt_rand(-500, 800);
            $points[] = [
                'timestamp' => $day->format('c'),
                'value'     => $base + $jitter,
                'period'    => 'daily',
                'series'    => $region,
                'metadata'  => ['currency' => 'USD'],
            ];
        }
        $logger->metrics($apiKey, 'revenue', $points);
        ok();
    } catch (LoggerException $e) {
        fail($e->getMessage());
    }
    pause($delay);
}

// ---------------------------------------------------------------------------
// 6. Hourly response-time batch
// ---------------------------------------------------------------------------

heading('6. Batch metrics (hourly response-time, last 24h)');

step('response-time batch (24 points)');
try {
    $points = [];
    for ($i = 23; $i >= 0; $i--) {
        $ts = $now->modify("-{$i} hours");
        // Simulated p95 with a daytime bump
        $hourOfDay = (int) $ts->format('G');
        $baseline = ($hourOfDay >= 9 && $hourOfDay <= 17) ? 120.0 : 60.0;
        $points[] = [
            'timestamp' => $ts->format('c'),
            'value'     => round($baseline + mt_rand(-20, 40) + mt_rand(0, 100) / 10, 1),
            'period'    => 'hourly',
            'metadata'  => ['unit' => 'ms', 'p' => 'p95'],
        ];
    }
    $logger->metrics($apiKey, 'response-time', $points);
    ok();
} catch (LoggerException $e) {
    fail($e->getMessage());
}

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

echo "\n" . color('  Done.', 'green') . ' All sends completed.' . "\n\n";

<!-- doc-type: reference -->

# Partner API Logger SDK for PHP

Send structured logs and metrics to the Partner API ingest service.

## Requirements

- PHP 8.1+

## Installation

```bash
composer require partner-api/logger
```

## Delivery: buffered since 2.0.0

Log calls **buffer and never throw**. `info()` / `warn()` / `error()` /
`debug()` / `logRequest()` / `logResponse()` append to an in-process queue and
return immediately; the queue is delivered after your response has been sent.
Our ingest being slow or down cannot slow down or fail the request you are
logging.

Upgrading from 1.x, two things change for you:

- **A `try`/`catch` around a log call no longer catches anything.** Delivery
  failures go to the `onError` callable (see [Error Handling](#error-handling)).
- **"The call returned" no longer means "delivered."** The buffer drains:
  1. when it reaches `batchSize` (default 100 entries),
  2. when you call `flush()`,
  3. at the end of the request, from a `register_shutdown_function` — after
     `fastcgi_finish_request()` where the SAPI provides it, so the client
     already has its response. Under Laravel a `terminating` callback drains it
     slightly earlier, which also covers Octane and queue workers.

  Call `flush()` yourself anywhere entries must have landed before the process
  moves on — a worker loop iteration, a long-running artisan command, or just
  before a deliberate `exit`.

`metric()` / `metrics()` are **not** buffered: a metric submission is an
explicit write you are entitled to a receipt for, so it still posts
synchronously and still throws `LoggerException` on failure.

Need the 1.x behaviour — a POST per call, an exception on failure? Pass
`['mode' => Logger::MODE_DIRECT]` (see [Options](#options)).

## Laravel Setup

The package auto-discovers in Laravel. Publish the config file:

```bash
php artisan vendor:publish --tag=partner-logger-config
```

Add your credentials to `.env`:

```env
PARTNER_API_TENANT_TOKEN=tenant_live_xxxxxxxxxxxx
PARTNER_API_BASE_URL=https://ingest.partnerapi.com
```

### Using the Facade

```php
use PartnerApi\Logger\Laravel\Facades\PartnerLogger;

PartnerLogger::info($apiKey, 'Order created', ['orderId' => $order->id]);
```

### Using Dependency Injection

```php
use PartnerApi\Logger\Logger;

class OrderController extends Controller
{
    public function store(Request $request, Logger $logger)
    {
        // ...
        $logger->info($apiKey, 'Order created', ['orderId' => $order->id]);
    }
}
```

## Standalone Usage (without Laravel)

```php
use PartnerApi\Logger\Logger;

$logger = new Logger(
    tenantToken: 'tenant_live_xxxxxxxxxxxx',
    baseUrl: 'https://ingest.partnerapi.com', // optional, this is the default
);

// ... log during the request ...

// Optional: the shutdown hook does this for you at the end of a web request.
$logger->flush();
```

## Options

A fifth constructor argument tunes the buffered transport. Every key is
optional.

```php
$logger = new Logger(
    tenantToken: 'tenant_live_xxxxxxxxxxxx',
    options: [
        'mode' => Logger::MODE_BUFFERED, // or Logger::MODE_DIRECT for the 1.x profile
        'onError' => fn (LoggerErrorEvent $e) => Log::warning($e->message),
        'batchSize' => 100,   // buffered entries that trigger a drain; 0 = only flush()/shutdown
        'maxBufferSize' => 1000,  // entries held before the OLDEST are dropped
        'maxRetries' => 3,     // retries per batch on network faults / 408 / 429 / 5xx
        'retryBaseDelayMs' => 200,
        'retryMaxDelayMs' => 5000,
        'requestTimeoutMs' => 5000, // 0 disables (Guzzle's own default: no deadline)
        'flushOnShutdown' => true,
        'finishRequestOnShutdown' => true,
    ],
);
```

Under Laravel these are all `config/partner-logger.php` keys
(`mode`, `batch_size`, `max_buffer_size`, `max_retries`, …) with matching
`PARTNER_API_LOG_*` environment variables.

### Buffer counters

```php
$logger->stats(); // ['buffered' => 12, 'delivered' => 480, 'dropped' => 0]
```

## Logging

```php
$logger->info($apiKey, 'Something happened', ['key' => 'value']);
$logger->warn($apiKey, 'Watch out');
$logger->error($apiKey, 'Something broke', ['error' => $e->getMessage()]);
$logger->debug($apiKey, 'Debug details');
```

### Context

Context fields persist across log calls and are included automatically:

```php
$logger->setContext([
    'partnerId' => 'partner-123',
    'requestId' => $request->header('x-request-id'),
]);

// Both logs include partnerId and requestId
$logger->info($apiKey, 'Step 1');
$logger->info($apiKey, 'Step 2');
```

Recognised keys: `partnerId`, `requestId`, `correlationId`, `path`, `method`,
`statusCode`, `duration`, plus `direction` (`'inbound'` / `'outbound'`),
`upstreamIntegration` and `upstreamBaseUrl` for attributing calls your tenant
makes to an upstream integration. Context is snapshotted when the entry is
buffered, so a later `setContext()` never re-labels entries already queued.

### HTTP Request / Response Logging

```php
// logRequest returns the correlation ID for pairing with the response
$correlationId = $logger->logRequest($apiKey, [
    'method' => $request->method(),
    'path' => $request->path(),
    'headers' => $request->headers->all(),
    'body' => $request->all(),
]);

$logger->logResponse($apiKey, [
    'statusCode' => 200,
    'headers' => ['content-type' => 'application/json'],
    'body' => $responseData,
    'duration' => $durationMs,
    'correlationId' => $correlationId,
]);
```

Sensitive headers (`Authorization`, `Cookie`, `X-API-Key`, etc.) are automatically redacted.

## PII Redaction Helper

For call sites that need to redact PII before passing user data into a downstream system whose logs you don't control, the package exposes a public `redactPII()` helper. The ruleset mirrors what the ingest service applies internally — emails, JWTs, Bearer tokens, named API-key prefixes, passwords, phone numbers, credit cards, IPv4/IPv6 addresses, and URL query strings.

```php
use PartnerApi\Logger\RedactPii;
use function PartnerApi\Logger\redactPII;

// Strings (regex pass)
redactPII('contact alice@example.com'); // → 'contact [EMAIL_REDACTED]'
redactPII('Authorization: Bearer abc.def.ghi'); // → 'Authorization: Bearer [TOKEN_REDACTED]'

// Headers (sensitive keys replaced wholesale)
redactPII([
    'Authorization' => 'Bearer secret',
    'X-Api-Key'     => 'sk-livetestkey1234567890',
    'Cookie'        => 'session=abc',
]);
// → ['Authorization' => 'Bearer [TOKEN_REDACTED]', 'X-Api-Key' => '[KEY_REDACTED]', 'Cookie' => '[COOKIE_REDACTED]']

// JSON body / deeply nested arrays
redactPII([
    'user' => [
        'email'       => 'alice@example.com',
        'credentials' => ['password' => 'hunter2', 'refresh_token' => 'rt_xyz'],
    ],
]);
// → ['user' => ['email' => '[EMAIL_REDACTED]', 'credentials' => ['password' => '[PASSWORD_REDACTED]', 'refresh_token' => '[TOKEN_REDACTED]']]]

// Query strings (encoded as associative array)
redactPII(['user' => 'alice', 'api_key' => 'sk-livetestkey1234567890']);
// → ['user' => 'alice', 'api_key' => '[KEY_REDACTED]']
```

Use the static form `RedactPii::redact(...)` when a use-function import is awkward. Options:

```php
RedactPii::redact($input, [
    'preserveStructure'   => true,  // keep sensitive keys with redacted placeholder (default true; false drops the key)
    'redactEmails'        => true,
    'redactApiKeys'       => true,
    'redactTokens'        => true,
    'redactPasswords'     => true,
    'redactPhoneNumbers'  => true,
    'redactCreditCards'   => true,
    'redactIpAddresses'   => true,
    'redactUrls'          => true,
    'redactUuids'         => false, // off by default — opt in for log-line redaction
]);
```

The original input is never mutated — a redacted copy is returned. Scalars (int/float/bool) and `null` pass through untouched.

## Metrics

Send business metrics to track over time:

```php
// Single data point
$logger->metric($apiKey, [
    'slug' => 'revenue',
    'timestamp' => '2024-06-01T00:00:00Z',
    'value' => 54000,
    'period' => 'Jun 01',
    'metadata' => ['currency' => 'EUR'], // optional
]);

// Batch
$logger->metrics($apiKey, 'revenue', [
    ['timestamp' => '2024-06-01T00:00:00Z', 'value' => 54000, 'period' => 'Jun 01'],
    ['timestamp' => '2024-06-08T00:00:00Z', 'value' => 55200, 'period' => 'Jun 08'],
    ['timestamp' => '2024-06-15T00:00:00Z', 'value' => 58100, 'period' => 'Jun 15'],
]);
```

## Error Handling

**Log calls never throw.** Delivery problems — a dead ingest, a rejected
batch, a full buffer, a call with no API key — are handed to the `onError`
callable. It defaults to a single `error_log()` of the message, which is the
line 1.x wrote to stderr.

```php
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;

$logger = new Logger(
    tenantToken: 'tenant_live_xxxxxxxxxxxx',
    options: [
        'onError' => function (LoggerErrorEvent $event): void {
            // $event->reason: 'flush-failed' | 'buffer-overflow' | 'invalid-entry'
            // $event->message is the exact string 1.x would have thrown
            // ('Failed to send log: …', 'API key is required for logging')
            // $event->entryCount / ->droppedTotal / ->status / ->attempts
            // ->retryable / ->cause
            error_log("[partner-logger] {$event->reason}: {$event->message}");
        },
    ],
);
```

Anything thrown from the hook is swallowed — a broken error handler is not
worth breaking the request over.

`metric()` and `metrics()` are the exception: they still throw
`PartnerApi\Logger\LoggerException` on validation or transport failure.

```php
use PartnerApi\Logger\LoggerException;

try {
    $logger->metric($apiKey, ['slug' => 'revenue', /* … */]);
} catch (LoggerException $e) {
    // Handle failure
}
```

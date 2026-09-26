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
Our ingest being slow or down cannot fail the request you are logging, and the
time it can cost that request is bounded — see
[What this costs your request](#what-this-costs-your-request) for the exact
numbers.

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

## What this costs your request

Delivery is synchronous — PHP has no event loop to hand it to — so "buffered"
has to mean _bounded_, not _free_. Two budgets do that, and nothing else in the
SDK blocks:

|                                   | Runs                                       | Worst case                                |
| --------------------------------- | ------------------------------------------ | ----------------------------------------- |
| **A single log call**             | drain 2, only once `batchSize` is reached  | `autoDrainTimeoutMs` — **1 s** by default |
| **Whole request, log calls only** | —                                          | `autoDrainTimeoutMs`, once                |
| **End-of-request drain**          | drains 1 and 3, after the response is sent | `drainDeadlineMs` — **5 s** by default    |

The request-path drain is deliberately cheap: **one** attempt on a short
deadline, **no** backoff sleep, and a chunk that failed for a retryable reason
goes back on the buffer instead of being retried while your caller waits. If it
fails it latches off for the rest of the request, so N log calls can never cost
N timeouts.

The retries live in the end-of-request drain, where they cost your caller
nothing, and `drainDeadlineMs` bounds that drain _as a whole_ — every group,
chunk, retry and backoff, with each individual attempt clamped to whatever is
left of the budget. Entries still undelivered when it runs out are reported to
`onError` with reason `drain-timeout` and dropped.

> **PHP-FPM, `fastcgi_finish_request()`:** the shutdown drain calls
> `fastcgi_finish_request()` before it posts, so the client has its response
> first. Under Laravel or any Symfony-based stack that is a no-op — the
> framework already called it — but on a bare FPM app it means this SDK ends
> the response, and any shutdown function your application registered _after_
> its first log call will no longer be able to write output. Pass
> `finishRequestOnShutdown => false` if your app needs to own that moment.

> **PHP-FPM, `request_terminate_timeout`:** the end-of-request drain runs inside the same FPM request as the
> response it already sent, so it counts against `request_terminate_timeout`.
> Keep `drainDeadlineMs` comfortably under that value (the default 5 s sits
> well inside a typical 30 s) or FPM will kill the worker mid-drain and the
> buffer dies with it. Note that `max_execution_time` will _not_ save you here:
> on Unix its timer does not tick during a blocking socket wait.

Set `drainDeadlineMs => 0` to opt out of the bound entirely, and
`batchSize => 0` to opt out of the request-path drain entirely (one POST at the
end of the request, and log calls that never touch the network).

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

### Where the two credentials come from

They are not the same kind of thing, and only one of them is yours:

- **`tenantToken`** is a single secret for your whole tenant. Every partner's
  traffic travels on it, so it lives in your environment
  (`PARTNER_API_TENANT_TOKEN`) and is set once, when the `Logger` is
  constructed — under Laravel, by the config file above.
- **`$apiKey`** — the first argument to every log and metric call — is **not**
  looked up. Each partner app sends its own key on the request it makes to
  _your_ API, in the `x-api-key` header. Read that value off the incoming
  request and pass it straight through. That is what tells one partner's
  traffic from another's; hardcode a single key and every partner's logs arrive
  labelled as the same app.

### Using the Facade

```php
use PartnerApi\Logger\Laravel\Facades\PartnerLogger;

$apiKey = $request->header('x-api-key'); // sent by the partner app

PartnerLogger::info($apiKey, 'Order created', ['orderId' => $order->id]);
```

### Using Dependency Injection

```php
use PartnerApi\Logger\Logger;

class OrderController extends Controller
{
    public function store(Request $request, Logger $logger)
    {
        $apiKey = $request->header('x-api-key'); // sent by the partner app
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
        'autoDrainTimeoutMs' => 1000, // the most ONE log call can cost the request
        'drainDeadlineMs' => 5000,    // total budget for a flush / end-of-request drain; 0 disables
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

**Log bodies to get entity views.** Entity View rebuilds each entity's history
from the `body` you pass to `logRequest()` and `logResponse()`. If you log no
body, there is no entity view. Pass the decoded array (`$request->json()->all()`
for a JSON API). `$request->all()`, used above, also merges in query-string
parameters. A body passed as a string (form-encoded, text or a JSON string) is
stored as a string, and only a JSON object contributes fields. PHP encodes an
empty array as `[]`, a list, so an empty body contributes nothing. Ingest
redacts personal data and credentials in bodies before storing them, and it
replaces any log line larger than 250 KB with a marker that carries no body.
The full list of limits is in `docs/features/entity-view.md` § Projection
fidelity limits.

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
            // $event->reason:
            //   'flush-failed'    — a batch was refused and discarded
            //   'buffer-overflow' — maxBufferSize reached, oldest dropped
            //   'invalid-entry'   — the call itself was unusable
            //   'drain-timeout'   — drainDeadlineMs ran out, remainder dropped
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

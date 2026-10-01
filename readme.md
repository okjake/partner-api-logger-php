<!-- doc-type: reference -->

# Partner API Logger SDK for PHP

Send structured logs and metrics to the Partner API ingest service.

## Requirements

- PHP 8.1+
- No PHP extension beyond the ones every PHP 8.1 build has (json, pcre). In
  particular ext-bcmath is not needed, so the official `php:*-cli` images
  work as they are. HTTP goes through Guzzle, which uses ext-curl when it is
  installed and PHP streams (`allow_url_fopen`) otherwise.

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
$logger->stats();
// ['buffered' => 12, 'delivered' => 480, 'dropped' => 0, 'upstreamDropped' => 0]
```

`upstreamDropped` counts upstream calls dropped for want of a response line —
see [Upstream call trail](#upstream-call-trail-on-the-response-line-papi-5337).

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

Outside a request scope, context is **logger-wide** — right for PHP-FPM, where
each request has its own process. On a long-running worker, see
[Per-request context](#per-request-context-on-a-long-running-worker-papi-5336).

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

## Per-request context on a long-running worker (PAPI-5336)

PHP-FPM and the CLI run one request per process, so the logger never outlives
the request and logger-wide context is exactly right. **Nothing changes for
you there.**

A long-running worker is different. Octane, RoadRunner and Swoole serve many
requests from one process, and a queue worker runs many jobs, so a singleton
logger outlives each one. Logger-wide context then leaks: request B starts
with request A's `partnerId`, `correlation_id` and `upstream_integration`, and
an upstream call request A recorded but never answered ships on request B's
response. Open a scope per request:

```php
// Octane / RoadRunner middleware — once, at the edge of the request.
public function handle(Request $request, Closure $next)
{
    return $this->logger->runWithContext(
        ['requestId' => $request->header('x-request-id')],
        fn () => $next($request),
    );
}

// Anywhere downstream, the shared logger resolves to this request's scope.
$logger->setContext(['direction' => 'outbound', 'upstreamIntegration' => 'stripe']);
$logger->info($apiKey, 'Calling Stripe'); // carries THIS request's requestId
```

- **`runWithContext($context, $fn)`** starts the scope as a copy of the current
  context merged with `$context`, runs `$fn`, and returns what `$fn` returns.
  Inside it, `setContext()` — and the fields `logRequest()`/`logResponse()`
  set — belong to that request only. Scopes nest. The previous scope comes back
  when `$fn` returns **and** when it throws (the exception reaches you
  unchanged). `$fn` also receives a logger bound to the scope
  (`fn (Logger $scoped) => …`).
- **`child($context)`** returns a `Logger` bound to a new scope. It shares the
  parent's buffer, transport, counters, `onError`, `flush()` and `stats()`.
  Create one per request and pass it down.
- **Coroutines and fibers.** `runWithContext()` is ambient for the
  _synchronous_ duration of `$fn`, which is what Octane and RoadRunner give you
  (one request per worker at a time). A server that interleaves requests
  inside one process — Swoole coroutines, AMPHP/ReactPHP fibers — must use
  `child()` per request instead: it never reads ambient state.
- **Log the response inside the scope.** The scope ends when `$fn` returns, so
  a `logResponse()` from a terminate hook that runs after `$fn` lands outside
  it.
- **The SDK's own events belong to no request.** `onError` runs outside every
  scope, so a hook that logs on this logger writes a logger-wide line. The
  end-of-request drain does too.
- **Unchanged if you never open a scope.** Calls outside any scope behave
  exactly as before, and the `logRequest()`/`logResponse()` pairing by
  `correlationId` is the same inside a scope as outside it.

Under Laravel the facade resolves the same singleton, so
`PartnerLogger::runWithContext(...)` and `PartnerLogger::child(...)` work as
above.

## Upstream call trail on the response line (PAPI-5337)

When a partner request fails because something _you_ called failed — Stripe
timed out, your pricing service returned a 503 — the response line can say so.
Record each upstream call against the request, and `logResponse()` ships them
as `upstream: [...]` on the `Outgoing response` line, then clears them:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

// Once, where you build the client. Every request through it is recorded
// against whichever request makes it.
$stack = HandlerStack::create();
$stack->push($logger->upstreamMiddleware('stripe'));
$stripe = new Client(['handler' => $stack]);

// In a handler:
$correlationId = $logger->logRequest($apiKey, ['method' => 'POST', 'path' => '/orders']);
$charge = $stripe->post('https://api.stripe.com/v1/charges', ['form_params' => $body]);

// Anything that is not Guzzle: record it yourself.
$logger->upstream([
    'name' => 'pricing',
    'method' => 'GET',
    'url' => 'https://pricing.internal/quote',
    'status' => 200,
    'durationMs' => 41,
]);

$logger->logResponse($apiKey, ['statusCode' => 201, 'duration' => $ms, 'correlationId' => $correlationId]);
```

The response line then carries, in the order the calls were made:

```json
"upstream": [
  { "name": "stripe", "method": "POST", "url": "https://api.stripe.com/v1/charges", "status": 200, "durationMs": 212, "requestId": "req_8Hk2…" },
  { "name": "pricing", "method": "GET", "url": "https://pricing.internal/quote", "status": 200, "durationMs": 41 }
]
```

- **`$logger->upstream($call)`** records `name`, `method`, `url`,
  `durationMs`, and optionally `status`, `requestId`, `errorCode`, `message`
  and `attempt` — the spec's field names, exactly. `name` is any label you
  like; there is no registration. Omit `status` for a network error (no HTTP
  response) and set `errorCode`. `attempt` is yours to set if you retry, or is
  read from Guzzle's own retry counter by `upstreamMiddleware()` (see Retries
  below), where the first attempt is `0`; the SDK never guesses it. Numbers
  follow the TypeScript SDK's rules: `200.0` is the status 200, the string
  `'200'` is not a status. A call missing `name`/`method`/`url`, or with a
  `method` that is not an HTTP method (letters, `-`, `_`; at most 16), a
  non-HTTP `status` or a bad `durationMs`, is
  dropped and reported to `onError` — `upstream()` never throws.
- **`$logger->upstreamMiddleware($name, $options = [])`** is the PHP
  counterpart of the TypeScript SDK's `wrapFetch`. Guzzle composes behaviour
  on a `HandlerStack` rather than by wrapping a function, so it returns a
  middleware to push. Every request through it records method, URL, status,
  duration, and the vendor's request id from the first of `request-id`,
  `x-request-id`, `x-amzn-requestid`, `x-amz-request-id`, `x-ms-request-id`,
  `x-github-request-id` and `cf-ray` present on the response
  (`UpstreamTrail::DEFAULT_REQUEST_ID_HEADERS`). Add your own with
  `['requestIdHeaders' => ['x-vendor-trace']]`; those are checked first.
  - An HTTP error is recorded as its status — whether Guzzle's `http_errors`
    turns it into an exception or not. The response body is never read.
  - A transport error (DNS, refused, timeout) is recorded with no status, an
    `errorCode` (`CURLE_COULDNT_CONNECT`, `CURLE_OPERATION_TIMEDOUT`, … or the
    exception's class name) and the transport's `message`. The exception
    reaches your code unchanged.
  - **Retries.** A `HandlerStack` nests in push order, so the first middleware
    pushed is the outermost. Push Guzzle's `Middleware::retry()` _before_ this
    one and every attempt is its own call, with `attempt` taken from the retry
    middleware's own counter: `0` for the first attempt, `1` for the first
    retry. Push it after, and a retried request is one call
    with no `attempt`.
  - **Redirects.** Pushed onto `HandlerStack::create()`, it sits inside the
    redirect middleware, so each redirect hop is its own call. `unshift()` it
    instead to record one call per request.
  - Recording never throws into your request; a failure to record is reported
    to `onError`. The logger's own POSTs to ingest are never recorded, even
    if the logger shares the client.
  - Each call is attributed to the scope active when the request is _sent_,
    so one client built at boot serves every request correctly under
    `runWithContext()` — async requests (`getAsync()`, pools) included. A
    middleware made from a `child()` always records into that child's scope.
- **Only the response line carries it.** No extra lines are emitted, and
  request/info lines are unchanged. A response with no calls has no `upstream`
  key at all — the line is byte-identical to earlier versions.
- **Query strings never leave the process.** The URL's query string, fragment
  and userinfo are stripped before the call is stored (ingest strips them
  again). The middleware also strips them from a transport error's message,
  where Guzzle repeats the full URL.
- **Capped at 20 calls / 8 KB** per response. Beyond either, the oldest calls
  are dropped and the line gets `_upstreamTruncated: true` and
  `_upstreamDropped: n`. Long `name`/`url`/`errorCode`/`message` values are cut
  to 128/2048/128/1024 characters. The 8 KB is measured on the trail exactly
  as the line encodes it, which is never less than ingest measures, so a PHP
  trail never trips ingest's cap.
- **PHP-FPM needs nothing more.** Outside any scope, calls are held
  logger-wide and ship on the next `logResponse()` — on FPM, this request's.
  If a logger records a logger-wide call after it has already logged a
  logger-wide response (it is outliving its request), that is reported once
  through `onError`.
- **On a long-running worker, scope it per request** (see above). The trail
  then lives in the request's scope, so one request never ships another's
  calls. These rules match the TypeScript SDK:
  - **Every scope ships its own calls first.** A _request_ is a scope that has
    called `logRequest()` or `logResponse()`; it is _mid-exchange_ between the
    two. Pre-flight calls made before `logRequest()` (an auth lookup, say)
    ship on that request's own response.
  - **A nested scope belongs to the exchange it was opened in.** A nested
    `runWithContext()` or a `child()` opened while the nearest request above
    is mid-exchange belongs to that request. Unless it becomes a request
    itself, its calls go onto the request's line: a nested `runWithContext()`
    hands them over when its `$fn` returns or throws, and a `child()` is
    pulled when the request responds. One opened between exchanges, or with
    no request above, is top-level and keeps its calls.
  - **Ordering and caps.** Handed-over calls keep call order, the caps apply
    on the request's trail, and a nested scope's own drops count toward
    `_upstreamDropped`. Nothing is pulled twice. Only calls move: a `child()`
    keeps its own context.
  - **Bounded waiting.** At most 1000 `child()` loggers wait, holding calls,
    for one request to pull them; past that the longest-waiting one is pulled
    early. The line that ships is the same.
  - **Many responses per scope.** Every request clears its trail after each
    response and keeps collecting for the next.
- **A call with no response line left is dropped, not parked.** When a
  top-level `runWithContext()` ends — its `$fn` returns or throws — any call
  it still holds, or that is recorded on its scoped logger later, is dropped.
  It never moves to another request's line or to the logger-wide trail. Drops
  are reported through `onError` (reason `invalid-entry`), at most once a
  minute, and whatever that held back is reported at `flush()`. Every drop is
  counted in `stats()['upstreamDropped']`. A top-level `child()` never ends:
  calls recorded on it after its last response ship on its next one.
- **Where PHP differs from TypeScript, and why.** TypeScript treats a
  synchronous return from `runWithContext`'s callback as _not_ the end of the
  request, because Express's `() => next()` returns at once and the request
  carries on through `AsyncLocalStorage`. PHP has no continuation that
  outlives the call stack, so here the scope ends when `$fn` returns — there
  is no third "returned" state.

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

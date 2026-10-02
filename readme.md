<!-- doc-type: reference -->

# Partner API Logger SDK for PHP

Sends structured logs and metrics to the Partner API ingest service, or
writes the logs to stdout for your own log pipeline to deliver
([Pipeline delivery](#pipeline-delivery-stdout-mode)). It mirrors
`@partner-api/logger` 3.2.0; the cross-language contract is
`packages/logger-spec/spec.md`. Changes by version are in
[CHANGELOG.md](CHANGELOG.md).

## Requirements

- PHP 8.1 or later.
- No PHP extension beyond json and pcre, which every PHP 8.1 build has. The
  official `php:*-cli` images work as they are.
- `guzzlehttp/guzzle` ^7.0 and `ramsey/uuid` ^4.0, installed by Composer.
  Guzzle uses ext-curl when it is installed, and PHP streams otherwise, which
  need `allow_url_fopen`.

## Install

```bash
composer require partner-api/logger
```

## Quick start

The **tenant token** is one secret for your whole tenant, set once when the
`Logger` is constructed. **`$apiKey`**, the first argument of every log and
metric call, belongs to the partner app: each app sends its own key in the
`x-api-key` header of its requests to your API. Pass that value through; it is
what tells one partner's traffic from another's.

### Laravel

The package auto-discovers its service provider and the `PartnerLogger`
facade. Publish the config file and add your token to `.env`:

```bash
php artisan vendor:publish --tag=partner-logger-config
```

```env
PARTNER_API_TENANT_TOKEN=tenant_live_xxxxxxxxxxxx
```

Then log through the facade:

```php
use PartnerApi\Logger\Laravel\Facades\PartnerLogger;

$apiKey = (string) $request->header('x-api-key'); // sent by the partner app

PartnerLogger::info($apiKey, 'Order created', ['orderId' => $order->id]);
```

Type-hint `PartnerApi\Logger\Logger` to inject the same singleton. A missing
key is not an exception: the entry is dropped and reported to `onError`.

### Standalone

```php
use PartnerApi\Logger\Logger;

$logger = new Logger(tenantToken: getenv('PARTNER_API_TENANT_TOKEN'));

$logger->info($apiKey, 'Order created', ['orderId' => 42]);
```

Log calls buffer and return at once; the buffer is delivered after your
response has been sent ([Delivery and errors](#delivery-and-errors)).

## Logging and context

```php
$logger->info($apiKey, 'Something happened', ['key' => 'value']);
$logger->warn($apiKey, 'Watch out');
$logger->error($apiKey, 'Something broke', ['error' => 'Insufficient funds']);
$logger->debug($apiKey, 'Debug details');
```

`setContext()` merges fields into every later entry:

```php
$logger->setContext([
    'partnerId' => 'partner-123',
    'requestId' => 'req_abc123',
]);
```

Recognised keys are `partnerId`, `requestId`, `correlationId`, `path`,
`method`, `statusCode` and `duration`, plus `direction` (`'inbound'` or
`'outbound'`), `upstreamIntegration` and `upstreamBaseUrl` for calls your own
code makes to a service it uses (an upstream integration). Context is copied into an entry when it is buffered.
Outside a request scope it is logger-wide, which is right for PHP-FPM and the
CLI.

## Request scopes

When one logger serves many requests or jobs from one process (an Octane
worker with the logger warmed, RoadRunner, Swoole, a queue worker),
logger-wide context and upstream calls carry from one into the next. Give
each request or job its own scope, opened once at its edge. PHP-FPM and the
CLI need none. For draining on these servers, see
[Delivery and errors](#delivery-and-errors).

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PartnerApi\Logger\Logger;

class ScopePartnerLogs
{
    public function __construct(private Logger $logger)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        return $this->logger->runWithContext(
            ['requestId' => $request->header('x-request-id')],
            fn () => $next($request),
        );
    }
}
```

- **`runWithContext($context, $fn)`** starts a scope as a copy of the current
  context merged with `$context`, runs `$fn` and returns its result. While
  `$fn` runs, the shared logger resolves to that scope: `setContext()`, the
  fields `logRequest()` and `logResponse()` set, and recorded upstream calls
  stay in it. Scopes nest, and the previous one comes back when `$fn` returns
  or throws. `$fn` also receives a logger bound to the scope.
- **`child($context)`** returns a `Logger` bound to a new scope that shares
  the parent's buffer, transport, counters and `onError`. Use it where
  requests interleave in one process (Swoole coroutines, fibers):
  `runWithContext()` holds only for the synchronous duration of `$fn`.
- **Log the response inside the scope.** A `logResponse()` from a terminate
  hook that runs after `$fn` returns lands outside it.
- `onError` and the end-of-request drain run outside every scope. The facade
  resolves the same singleton, so `PartnerLogger::runWithContext()` works too.

## Request and response logging

```php
// Returns the correlation ID that pairs the request with its response.
$correlationId = $logger->logRequest($apiKey, [
    'method' => 'POST',
    'path' => '/api/v1/orders',
    'headers' => ['x-request-id' => 'req_abc123', 'authorization' => 'Bearer secret'],
    'body' => ['sku' => 'A-100', 'quantity' => 2],
]);

$logger->logResponse($apiKey, [
    'statusCode' => 201,
    'headers' => ['content-type' => 'application/json'],
    'body' => ['id' => 'ord_123', 'status' => 'created'],
    'duration' => 120, // milliseconds
    'correlationId' => $correlationId,
]);
```

- The correlation ID is the `x-correlation-id` header when you pass one,
  otherwise a new UUID.
- `headers` maps a lowercase name to a string (Laravel's
  `$request->headers->all()` gives lists; join them first). `authorization`,
  `cookie`, `set-cookie`, `x-api-key`, `x-tenant-token` and
  `proxy-authorization` become `[REDACTED]`, whatever their case.
- **Log bodies to get entity views.** Entity View is built from these `body`
  values. Pass the decoded array (`$request->json()->all()`): only a JSON
  object contributes fields, and an empty PHP array encodes as `[]`. Ingest
  redacts personal data in bodies and replaces a log line over 250 KB with a
  marker that has no body (`docs/features/entity-view.md` § Projection
  fidelity limits).

## Logging webhooks

**Incoming webhooks**, a partner calling a webhook endpoint of yours, are
ordinary inbound traffic: log them with `logRequest()` / `logResponse()` like
any other partner request.

What differs is how you know the partner. A webhook is usually authenticated
by a signature rather than by the partner's app key. Once your handler has
verified the signature and knows which partner sent it, pass **that
partner's app key** to the log calls. Do not leave a webhook out of your logs
because it carries no key, and do not log it under another partner's key: a partner sees only the
lines attributed to it.

```php
// After verifying the signature and finding the partner that sent it:
$apiKey = $appKeys->forPartner($partnerId); // your own mapping
$correlationId = $logger->logRequest($apiKey, [
    'method' => $request->method(),
    'path' => $request->path(),
    'headers' => [
        'content-type' => $request->header('content-type'),
        'x-signature' => '[REDACTED]',
    ],
    'body' => $request->json()->all(),
]);
```

Never log the signing secret, and replace the signature header's value
yourself, as above: the SDK redacts only `authorization`, `cookie`,
`set-cookie`, `x-api-key`, `x-tenant-token`, `proxy-authorization` and, in stdout mode, a header whose value equals the app key, so a header such as `x-signature` or
`stripe-signature` is logged as given.

**Outgoing webhooks**, your code delivering an event to a partner's endpoint:
Partner API does not yet show webhook deliveries to partners. An outbound line
is a call to a service you use and is never shown to a partner.

## Upstream call trail

Record the calls you make while serving a request, and `logResponse()` ships
them as `upstream: [...]` on its `Outgoing response` line, then clears them.

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

// Once, where you build the client. Every request through it is recorded
// against the request that makes it.
$stack = HandlerStack::create();
$stack->push($logger->upstreamMiddleware('stripe'));
$stripe = new Client(['handler' => $stack]);

// In a handler:
$correlationId = $logger->logRequest($apiKey, ['method' => 'POST', 'path' => '/orders']);
$stripe->post('https://api.stripe.com/v1/charges', ['form_params' => ['amount' => 1000]]);

// Anything that is not Guzzle: record it yourself.
$logger->upstream([
    'name' => 'pricing',
    'method' => 'GET',
    'url' => 'https://pricing.internal/quote',
    'status' => 200,
    'durationMs' => 41,
]);

$logger->logResponse($apiKey, ['statusCode' => 201, 'duration' => 250, 'correlationId' => $correlationId]);
```

The response line then carries the calls in the order they were made:

```json
"upstream": [
  { "name": "stripe", "method": "POST", "url": "https://api.stripe.com/v1/charges", "status": 200, "durationMs": 212, "requestId": "req_8Hk2" },
  { "name": "pricing", "method": "GET", "url": "https://pricing.internal/quote", "status": 200, "durationMs": 41 }
]
```

**`upstream($call)`** takes `name` (any label), `method`, `url` and
`durationMs`, and optionally `status`, `requestId`, `errorCode`, `message` and
`attempt`. Omit `status` for a network error. `status` must be an int (or a
whole float): `'200'` is not a status. A call ingest would reject (no
`name`, `method` or `url`, a `method` other than letters, `-` and `_` up to 16
characters, a status outside 100 to 599, a `durationMs` that is not a finite
non-negative number) is dropped and reported to `onError`. It never throws.

**`upstreamMiddleware($name, $options = [])`** returns a Guzzle middleware
that records every request through it: method, URL, status, duration, and the
vendor's request id from the first header present in
`$options['requestIdHeaders']` and then
`UpstreamTrail::DEFAULT_REQUEST_ID_HEADERS` (`request-id`, `x-request-id`,
`x-amzn-requestid`, `x-amz-request-id`, `x-ms-request-id`,
`x-github-request-id`, `cf-ray`). Any other option throws `LoggerException`
when the middleware is made.

- An HTTP error is recorded with its status, whether or not `http_errors`
  turns it into an exception. The body is never read.
- A transport error is recorded with no status, an `errorCode`
  (`CURLE_COULDNT_CONNECT`, `CURLE_OPERATION_TIMEDOUT`, ..., or the
  exception's short class name) and its message. The exception reaches your
  code unchanged.
- A call belongs to the scope active when the request is sent, async requests
  included, so one client built at boot serves every request. The logger's own
  POSTs to ingest are never recorded.

**Where to push it.** A `HandlerStack` nests in push order, first pushed
outermost. Pushed onto `HandlerStack::create()`, it sits inside the redirect
middleware, so each redirect hop is its own call; `unshift()` it for one call
per request. Push Guzzle's retry middleware before it to record each attempt
as its own call, with `attempt` taken from the retry counter (`0` for the
first attempt). Anywhere else `attempt` is omitted.

```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = HandlerStack::create();
$stack->push(Middleware::retry(fn (int $retries, $request, $response = null) => $retries < 2 && $response?->getStatusCode() >= 500));
$stack->push($logger->upstreamMiddleware('stripe'));
```

**Caps.** At most 20 calls and 8 KB of trail per line
(`UpstreamTrail::MAX_CALLS`, `MAX_BYTES`); past either, the oldest calls are
dropped and the line gets `_upstreamTruncated: true` and `_upstreamDropped: n`.
`name`, `url`, `errorCode` and `message` are cut to 128, 2048, 128 and 1024
UTF-16 code units, and a `requestId` over 128 is left out. A URL's query
string, fragment and userinfo are stripped before the call is stored, and from
a transport error's message.

**Which line carries it.** Only the response line; a response with no calls
has no `upstream` key.

- Outside any scope, calls ship on the next `logResponse()` made outside a
  scope: on PHP-FPM, this request's. If the logger has already logged a
  response outside any scope when it records such a call, `onError` gets a
  one-time warning: the logger is outliving its request and needs scopes.
- A scope opened between a request's `logRequest()` and `logResponse()`
  ships its calls on that request's line, unless it logs its own.
- Calls left in a top-level `runWithContext()` when it ends, or recorded
  through its scoped logger afterwards, are dropped, never moved to another
  request. Drops are reported as `invalid-entry` at most once a minute (the
  rest at `flush()`) and counted in `stats()['upstreamDropped']`.

## PII redaction helper

`redactPII()` redacts personal data before you hand it to a system whose
logs you do not control. It applies the ingest service's rules: this package,
ingest and `@partner-api/logger` all run the shared corpus in
`packages/database/src/pii-redaction-corpus.json`.

```php
use PartnerApi\Logger\RedactPii;
use function PartnerApi\Logger\redactPII;

redactPII('contact alice@example.com');
// → 'contact [EMAIL_REDACTED]'

redactPII('call me on +44 20 7946 0000 today');
// → 'call me on [PHONE_REDACTED] today'

redactPII('GET https://api.example.com/v1/orders?token=abc 200');
// → 'GET https://api.example.com/v1/orders?[QUERY_REDACTED] 200'

redactPII([
    'Authorization' => 'Bearer secret',
    'X-Api-Key' => 'abc123',
    'user' => ['email' => 'alice@example.com', 'password' => 'hunter2'],
]);
// → ['Authorization' => 'Bearer [TOKEN_REDACTED]', 'X-Api-Key' => '[KEY_REDACTED]', 'user' => ['email' => '[EMAIL_REDACTED]', 'password' => '[PASSWORD_REDACTED]']]

redactPII(['+44 20 7946 0000' => 'sent', '+44 20 7946 0001' => 'failed']);
// → ['[PHONE_REDACTED]' => 'sent', '[PHONE_REDACTED]#2' => 'failed']

RedactPii::redact('task_123e4567-e89b-12d3-a456-426614174000', ['redactUuids' => true]);
// → 'task_[ID]'
```

In a string it redacts:

- emails, JWTs, Bearer tokens and `access_token=` / `refresh_token=` values;
- issuer-prefixed credentials such as GitHub, AWS, Stripe and the platform's
  own tokens; also generic `sk-`, `pk_`, `rk_`, `ak_`, `key_` and `token_`
  keys, 32+ alphanumeric runs and 40+ character base64 runs;
- passwords in `password=` / `pwd:` assignments and `"password": "..."` JSON;
- phone numbers: an international number however it is grouped, keeping a
  status code, date or unit written after it. A signed count (`+12`, `+30s`)
  is not a phone number, and ten bare digits go only near a word such as
  `phone` or `call`;
- card numbers, CVVs, and IPv4 and IPv6 addresses;
- URL query strings: the URL keeps its host and path and ends in
  `?[QUERY_REDACTED]`. A long REST path keeps its words and loses only its
  id-shaped pieces. A host outside ASCII (`café.test`) is kept as written,
  not converted to punycode, which would need ext-intl;
- UUIDs, only with `redactUuids`.

A card, token, JWT, IP or phone number after a JSON escape (`\n`) goes too.

In an array, a sensitive key (`password`, `apiKey`, `clientSecret`, `cookie`,
...) keeps its key and loses its value. A key that is itself PII is redacted
like a value; keys that redact alike keep both values, the second under `#2`.
A `__proto__` key is dropped. Lists are walked item by item.

A redacted copy is returned; the input is never modified. Ints, floats, bools
and `null` pass through. **Objects pass through unredacted**, including a
`stdClass` from `json_decode($json)`: decode with `json_decode($json, true)`
first.

**Fail-closed.** If PHP's regex engine reports an error on a string (its
backtrack or JIT stack limit), the whole string becomes `[REDACTION_FAILED]`
(`RedactPii::REDACTION_FAILED`), never unredacted or half redacted. The same
goes for an array key. The helper never throws.

**Linear time and memory.** Every rule is linear in its input, with PCRE's
JIT on or off. The test suite holds a 1 MB string of each adversarial shape to
under a second and 48 MB of peak memory, at the default 128 MB
`memory_limit`.

Options are booleans: `preserveStructure` (`false` drops a sensitive key
instead of masking it), `redactEmails`, `redactApiKeys`, `redactTokens`,
`redactPasswords`, `redactPhoneNumbers`, `redactCreditCards`,
`redactIpAddresses` and `redactUrls`, all `true` by default, and
`redactUuids`, `false` by default.

## Metrics

```php
// One data point
$logger->metric($apiKey, [
    'slug' => 'revenue',
    'timestamp' => '2024-06-01T00:00:00Z',
    'value' => 54000,
    'period' => 'Jun 01',
    'metadata' => ['currency' => 'EUR'], // optional
]);

// Several points for one metric
$logger->metrics($apiKey, 'revenue', [
    ['timestamp' => '2024-06-01T00:00:00Z', 'value' => 54000, 'period' => 'Jun 01'],
    ['timestamp' => '2024-06-08T00:00:00Z', 'value' => 55200, 'period' => 'Jun 08'],
]);
```

A point may also carry a `series` of at most 50 characters. A slug is
lowercase letters, digits and hyphens; one call takes at most 1000 points.
Metrics are not buffered: each call posts at once and throws
`PartnerApi\Logger\LoggerException` on a validation or delivery failure.

## Options

The constructor takes `tenantToken`, then optional `baseUrl` (default
`https://ingest.partnerapi.com`), `httpClient` (any Guzzle `ClientInterface`),
`timestampProvider` (a callable returning epoch milliseconds) and `options`.
Pass them by name. An unknown option or `mode`, an unusable `stdoutSink`, and
an empty `tenantToken` in stdout mode throw `LoggerException` at
construction.

| Option                    | Default                 | Laravel env (`PARTNER_API_LOG_*`) | Effect                                                                                                                                                                                                                     |
| ------------------------- | ----------------------- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `mode`                    | `Logger::MODE_BUFFERED` | `MODE`                            | `Logger::MODE_DIRECT` posts on every log call, once, and throws `LoggerException` on failure; `Logger::MODE_STDOUT` writes each log call as one line to `stdoutSink` ([Pipeline delivery](#pipeline-delivery-stdout-mode)) |
| `stdoutSink`              | `'php://stdout'`        | `STDOUT_SINK`                     | Where `MODE_STDOUT` writes: a stream URI or file path, an open stream, or a callable taking each line                                                                                                                      |
| `onError`                 | one `error_log()`       | none                              | Receives a `LoggerErrorEvent` for every drop                                                                                                                                                                               |
| `batchSize`               | 100                     | `BATCH_SIZE`                      | Buffered entries that trigger a drain inside the log call; `0` leaves only `flush()` and the end-of-request drain                                                                                                          |
| `maxBufferSize`           | 1000                    | `MAX_BUFFER_SIZE`                 | Entries held before the oldest are dropped                                                                                                                                                                                 |
| `maxRetries`              | 3                       | `MAX_RETRIES`                     | Retries per batch on a network fault, 408, 429 or 5xx, in `flush()` and the end-of-request drain only                                                                                                                      |
| `retryBaseDelayMs`        | 200                     | `RETRY_BASE_DELAY_MS`             | First backoff window; it doubles per retry, with jitter                                                                                                                                                                    |
| `retryMaxDelayMs`         | 5000                    | `RETRY_MAX_DELAY_MS`              | Largest backoff window                                                                                                                                                                                                     |
| `requestTimeoutMs`        | 5000                    | `REQUEST_TIMEOUT_MS`              | Deadline for one POST, metrics included; `0` disables it                                                                                                                                                                   |
| `autoDrainTimeoutMs`      | 1000                    | `AUTO_DRAIN_TIMEOUT_MS`           | Budget for the drain in a log call, and on destroy; `0` disables it (on destroy, `requestTimeoutMs` per attempt)                                                                                                           |
| `drainDeadlineMs`         | 5000                    | `DRAIN_DEADLINE_MS`               | Budget for one `flush()` or end-of-request drain, retries included; `0` disables it                                                                                                                                        |
| `flushOnShutdown`         | `true`                  | `FLUSH_ON_SHUTDOWN`               | Drain from a `register_shutdown_function`, and on a long-running worker when a logger is destroyed                                                                                                                         |
| `finishRequestOnShutdown` | `true`                  | `FINISH_REQUEST`                  | Call `fastcgi_finish_request()` before that drain                                                                                                                                                                          |

`batchSize` is capped at `maxBufferSize` and at 1000. Under Laravel each
option is a snake_case key in `config/partner-logger.php`, read from the
variable shown; the token and base URL are `PARTNER_API_TENANT_TOKEN` and
`PARTNER_API_BASE_URL`.

## Delivery and errors

Log calls (`info`, `warn`, `error`, `debug`, `logRequest`, `logResponse`)
append to an in-process buffer and never throw. The buffer drains:

1. when it reaches `batchSize`, inside the log call that filled it;
2. when you call `flush()`;
3. at the end of the request. A `register_shutdown_function` drains it after
   `fastcgi_finish_request()`, so the client already has its response.

A shutdown function runs only when the process ends, so outside Laravel a
long-running worker calls `flush()` at the end of each request or job. Under
Laravel the provider drains the logger each HTTP request used, after its
response:

- Under PHP-FPM or `artisan serve`, the application's logger.
- Under Octane, the request's logger, warmed or not (since 2.1.1). Warming is
  still recommended: add `PartnerApi\Logger\Logger::class` to `warm` in
  `config/octane.php` and one logger serves the worker instead of one being
  built per request; the request scope API then keeps requests apart.
- In queue workers nothing drains per job: call `flush()` at the end of each
  job, or from `Queue::after()` / `Queue::failing()`.

Under PHP-FPM, mod_php and the other one-request-per-lifecycle servers the
end-of-request drain holds every logger until it runs, as it always has. On a
long-running worker (the `cli` SAPI: Octane, RoadRunner, Swoole,
`queue:work`) it does not keep a logger alive, so a dropped logger is
collected; one dropped with entries still buffered drains them as it is
destroyed (see [What this costs your request](#what-this-costs-your-request)).
FrankenPHP in classic mode (one request per lifecycle) reports the
`frankenphp` SAPI, so it gets the long-running behaviour too: the
destroy-time drain, bounded by `autoDrainTimeoutMs`. Nothing is lost; only
the timing differs from PHP-FPM. NGINX Unit's SAPI is outside the
per-request list too, and behaves the same way.

A child forked with `pcntl_fork()` inherits the parent's buffer and pending
end-of-request drain, and delivers the parent's buffered entries again when it
exits. Call `flush()` before forking.

A POST carries at most 1000 entries
and 1 MB of log lines.

### What this costs your request

PHP has no event loop, so delivery is synchronous and bounded:

- **A log call** waits only for drain 1, for at most `autoDrainTimeoutMs`
  (1 s): one attempt per batch, no backoff, and anything undelivered goes back
  on the buffer. A failed drain switches drain 1 off until a later drain
  delivers everything, so an ingest outage costs a request one
  `autoDrainTimeoutMs` at most.
- **A logger dropped mid-request on a long-running worker** with entries
  still buffered (since 2.1.1) delivers them as it is destroyed, whenever its
  last reference goes: on a function return, or whenever the cycle collector
  runs. That is synchronous, so it costs what drain 1 costs: one attempt per
  batch, no backoff, at most `autoDrainTimeoutMs` for the whole drain. What it
  could not deliver is dropped and reported as `flush-failed` or
  `drain-timeout`. That is once per dropped logger: keep one for the request.
  PHP-FPM is unchanged: there the drain after the response holds the logger.
- **`flush()` and the end-of-request drain** carry the retries and are bounded
  as a whole by `drainDeadlineMs` (5 s). Entries still undelivered then are
  dropped and reported as `drain-timeout`.

On PHP-FPM the end-of-request drain counts against
`request_terminate_timeout`, so keep `drainDeadlineMs` well under it.
`max_execution_time` does not help: on Unix it does not tick while PHP waits
on a socket. On a bare FPM app the drain's
`fastcgi_finish_request()` ends the response, so a shutdown function
registered after the first log call can no longer write output; set
`finishRequestOnShutdown` to `false` if your app needs that.

### `onError`

Every drop goes to `onError`, which by default writes the message with
`error_log()`. Anything the hook throws is swallowed.

```php
use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;

$logger = new Logger(
    tenantToken: getenv('PARTNER_API_TENANT_TOKEN'),
    options: [
        'onError' => function (LoggerErrorEvent $event): void {
            error_log("[partner-logger] {$event->reason}: {$event->message}");
        },
    ],
);
```

| `$event->reason`  | Meaning                                                                                                                                        |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `flush-failed`    | A batch was refused (a 4xx other than 408 or 429, or retries ran out) and discarded                                                            |
| `buffer-overflow` | `maxBufferSize` was reached and the oldest entries were dropped                                                                                |
| `invalid-entry`   | The call itself was unusable: no API key, unserialisable data, a malformed request or response array; also a rejected or dropped upstream call |
| `drain-timeout`   | `drainDeadlineMs` ran out and the rest was dropped                                                                                             |
| `write-failed`    | Stdout mode only: the sink threw, could not be opened, or took less than the whole line. At most one a minute; `entryCount` is the lines lost  |
| `sink-warning`    | Stdout mode only, once per logger: under PHP-FPM with `php://stdout` or `php://stderr` as the sink, which FPM discards or splits               |

The event also carries `message`, `entryCount`, `droppedTotal`, and where they
apply `status`, `attempts`, `retryable` and `cause`.

### Counters

```php
$logger->stats();
// ['buffered' => 0, 'delivered' => 480, 'dropped' => 0, 'upstreamDropped' => 0]
```

`upstreamDropped` counts upstream calls dropped for want of a response line.

## Pipeline delivery (stdout mode)

If you already ship your logs through a pipeline of your own (an
OpenTelemetry Collector, Fluent Bit, Vector), the SDK can write each log call
as one JSON line to stdout instead of sending it, and your collector forwards
the lines to Partner API over OTLP/HTTP. Nothing else changes: the same calls,
the same facade, context, request scopes and upstream trail.

**Setting up the collector:** the [pipeline guide](docs/pipeline-collectors.md)
has tested configurations for the OpenTelemetry Collector, Fluent Bit and
Vector, troubleshooting, the limits, and the line format for writing lines
without the SDK.

**Choose it** when your services already log to stdout at volume and you do
not want a second shipper inside the process, with its own buffer, retries
and egress. **Stay on the default** (push) for small or serverless
deployments, or wherever no collector runs: push needs nothing besides the
SDK.

Switch with configuration, not code:

```env
PARTNER_API_LOG_MODE=stdout
```

```php
$logger = new Logger(
    tenantToken: getenv('PARTNER_API_TENANT_TOKEN'),
    options: ['mode' => Logger::MODE_STDOUT],
);
$logger->info($apiKey, 'Order created', ['orderId' => 42]);
```

```text
{"partnerapi_line":"1.6.0","partnerapi_partner_ref":"v1:7167…c676","partnerapi_timestamp":"1700000000000000000","partnerapi_level":"info","level":"info","message":"Order created","orderId":42}
```

- **Every line starts with `{"partnerapi_line":`.** Have your collector
  forward the lines that do to `POST https://ingest.partnerapi.com/v1/logs`
  (OTLP/HTTP) with a static `x-tenant-token` header, and send everything else
  wherever it already goes.
- **The line names the partner by a reference, never by its key.**
  `partnerapi_partner_ref` is `v1:` plus an HMAC of the app key's SHA-256,
  keyed with your tenant token. `PartnerApi\Logger\PartnerReference::v1($tenantToken, $appKey)`
  computes it if you write lines from your own logger.
- **The tenant token in the SDK must be the one your collector sends.** The
  reference is keyed on it, so a line written under another token matches no
  partner and is dropped at ingest, where it is counted; the SDK cannot see
  it.
- **Rolling the tenant token.** The old token stops working at once. Lines
  your app wrote under it, including any still waiting in your collector,
  match no partner once the token has changed: they are dropped and reported
  to the collector as `unmatched`. Roll in a quiet period, and redeploy the
  app and the collector with the new token in the same change.
- **Bodies and data travel through your pipeline as you pass them.** The SDK
  redacts the sensitive headers listed under
  [Request and response logging](#request-and-response-logging), and also any
  header whose value is exactly the app key, whatever the header is called.
  Everything else (request and response bodies, `data`, a key in a query
  string or a body) reaches your collector, and your own log store if it keeps
  these lines, unsanitised. Ingest redacts personal data when the line
  arrives, as it does for push. A collector's line-size limit can also cut a
  long body before it reaches ingest.
- **Strip the container wrapper first.** Container runtimes wrap each line
  (CRI: `<time> stdout F …`; Docker's json-file driver: `{"log":"…"}`) and
  split lines over 16 KiB. Your collector must unwrap and reassemble them
  before it filters on the prefix, or nothing matches. The OpenTelemetry
  Collector's `file_log` receiver has a `container` operator that removes
  either wrapper but rejoins only CRI (Kubernetes) partial lines: each file's
  separately only with `include_file_path: true`, and intact only with
  `preserve_trailing_whitespaces: true`. Docker json-file partial lines need a
  `recombine` operator. Neither separates stdout from stderr, so drop stderr
  before rejoining, and keep the SDK's lines on stdout. The [pipeline guide](docs/pipeline-collectors.md) has
  a tested configuration for each runtime. A file sink needs none of this.
- **A log call never touches the network.** No buffer, batching, retries or
  end-of-request drain; `flush()`, `shutdown()` and `close()` only flush the
  stream. `metric()` / `metrics()` still post to ingest as before.
- **Errors.** A missing key or unserialisable data is reported to `onError`
  as in push mode and nothing is written. A sink that fails is reported as
  `write-failed` (`Failed to write log: …`, with the OS's error where there is
  one), at most once a minute: each report carries the lines lost since the
  last, `stats()['dropped']` counts every one, and `flush()` reports what the
  minute held back. A line your `onError` logs while handling that failure is
  counted but not reported again. What happens after the write (in your
  collector, at ingest) never reaches the SDK. In `stats()`, `delivered`
  counts lines written in full.
- **Laravel:** the logger is built on its first use, so an empty
  `PARTNER_API_TENANT_TOKEN` in stdout mode throws `LoggerException` from the
  first `PartnerLogger::` call, not at boot.

### Sinks

`stdoutSink` takes:

- a **stream or file path**: `'php://stdout'` (the default), `'php://stderr'`,
  `'php://fd/<n>'`, a `file://` URI or a local file path, opened in append
  mode on the first line and kept open. Use an absolute path: a relative one
  resolves against the working directory, which differs between PHP-FPM,
  `artisan` and a queue worker. A string is always a path, never a function
  name. Anything else (`php://output`, which under PHP-FPM would write your
  log lines into the HTTP response, `php://memory`, `php://temp`, `http://`,
  `phar://`, …) throws `LoggerException` at construction.
  Lines written to `'php://stderr'` are dropped by the guide's Kubernetes and
  Docker collector configurations, which forward stdout only.
- an open, writable **stream resource**, which the logger never closes;
- a **callable** that receives each complete line, trailing `"\n"` included,
  once per line.

Each line is a single `fwrite()` (or a single call), so lines from one process
never interleave. An emitter cut short mid-line ends the fragment with one
`"\n"`, so the next line keeps its prefix, and reports the line as lost
(ingest drops the fragment as unparseable); the SDK does exactly that and
does not retry. Between processes sharing one output, a pipe write is atomic
only up to 4096 bytes on Linux, and a line with bodies is usually longer: give
each process its own output, or a file. Appends to one shared file from
several processes do not interleave on a local filesystem; on a network
filesystem (NFS, EFS) they can, so use one file per process there.

**Rotation.** A file sink stays open. At most once a second, the logger
checks whether its path still names the file it is writing to. When the file
was renamed or deleted (logrotate's default `create` mode), it opens the path
afresh and switches over only if that open succeeds; until then it keeps
writing to the file it has, reports nothing, and tries again a second later.
Lines written before the switch land in the rotated file. `copytruncate` keeps
the same file and needs no reopen.

### PHP-FPM

An FPM worker's stdout is not the container's stdout. FPM discards worker
stdout and stderr unless the pool sets `catch_workers_output = yes`. With it,
FPM writes worker output to its own error log, prefixes each line with
`[pool www] child 12 said into stdout:` unless `decorate_workers_output = no`,
and splits any line longer than `log_limit` (1024 bytes by default). The
official Docker images set `catch_workers_output = yes`,
`decorate_workers_output = no` and `log_limit = 8192`, and send that log to
the master's stderr: there the lines appear on the container's **stderr**, not
stdout, and are still split above 8192 bytes. A split line is lost at ingest.

So under FPM, point the sink at a file your collector tails:

```env
PARTNER_API_LOG_MODE=stdout
PARTNER_API_LOG_STDOUT_SINK=/var/log/app/partner-api.jsonl
```

A logger constructed under FPM with `php://stdout` or `php://stderr` as its
sink reports one `sink-warning` to `onError` saying so: once per logger
instance, not per line. Under FPM each request builds its own logger, so with
the default hook that is one `error_log()` line per request.

If you want one file per worker, build the logger in your own
service provider with `getmypid()` in the path: a value computed in
`config/partner-logger.php` is frozen by `php artisan config:cache`.

### Octane and other long-running processes

Under `php artisan octane:start` (Swoole, RoadRunner or FrankenPHP) your
workers run as child processes of a server that re-renders what they print:
a JSON line on a worker's stdout is decoded, re-encoded and printed behind a
label, so it loses its prefix, and repeated stderr lines are merged. Neither
reaches ingest, while `stats()` still counts the lines as written. **Under
Octane the sink must be a file, by absolute path:**

```env
PARTNER_API_LOG_MODE=stdout
PARTNER_API_LOG_STDOUT_SINK=/var/log/app/partner-api.jsonl
```

`php://stdout` is right only for a plain CLI process whose stdout is what your
container runtime captures: `queue:work` run directly (not under Horizon), or
a daemon of your own. If several such processes share one stdout, the pipe
limit above applies.

## Testing

Pass a Guzzle client backed by a `MockHandler`, a fixed `timestampProvider`
and `flushOnShutdown => false`. The `sleeper`, `randomizer` and `clock`
options replace the backoff sleep, its jitter and the monotonic clock (in
milliseconds).

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PartnerApi\Logger\Logger;

$sent = [];
$stack = HandlerStack::create(new MockHandler([new Response(200)]));
$stack->push(Middleware::history($sent));

$logger = new Logger(
    tenantToken: 'test-token',
    httpClient: new Client(['handler' => $stack]),
    timestampProvider: fn () => 1700000000000,
    options: ['flushOnShutdown' => false, 'batchSize' => 0],
);

$logger->info('app-key', 'Order created');
$logger->flush();

$body = json_decode((string) $sent[0]['request']->getBody(), true);
// $body['entries'][0]['timestamp'] === '1700000000000000000'
```

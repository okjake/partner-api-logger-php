<!-- doc-type: reference -->

# Partner API Logger SDK for PHP

Sends structured logs and metrics to the Partner API ingest service. It
mirrors `@partner-api/logger` 3.1.0; the cross-language contract is
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
`'outbound'`), `upstreamIntegration` and `upstreamBaseUrl` for calls to an
upstream integration. Context is copied into an entry when it is buffered.
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
Pass them by name. An unknown option or `mode` throws `LoggerException` at
construction.

| Option                    | Default                 | Laravel env (`PARTNER_API_LOG_*`) | Effect                                                                                                            |
| ------------------------- | ----------------------- | --------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `mode`                    | `Logger::MODE_BUFFERED` | `MODE`                            | `Logger::MODE_DIRECT` posts on every log call, once, and throws `LoggerException` on failure                      |
| `onError`                 | one `error_log()`       | none                              | Receives a `LoggerErrorEvent` for every drop                                                                      |
| `batchSize`               | 100                     | `BATCH_SIZE`                      | Buffered entries that trigger a drain inside the log call; `0` leaves only `flush()` and the end-of-request drain |
| `maxBufferSize`           | 1000                    | `MAX_BUFFER_SIZE`                 | Entries held before the oldest are dropped                                                                        |
| `maxRetries`              | 3                       | `MAX_RETRIES`                     | Retries per batch on a network fault, 408, 429 or 5xx, in `flush()` and the end-of-request drain only             |
| `retryBaseDelayMs`        | 200                     | `RETRY_BASE_DELAY_MS`             | First backoff window; it doubles per retry, with jitter                                                           |
| `retryMaxDelayMs`         | 5000                    | `RETRY_MAX_DELAY_MS`              | Largest backoff window                                                                                            |
| `requestTimeoutMs`        | 5000                    | `REQUEST_TIMEOUT_MS`              | Deadline for one POST, metrics included; `0` disables it                                                          |
| `autoDrainTimeoutMs`      | 1000                    | `AUTO_DRAIN_TIMEOUT_MS`           | Budget for the drain inside a log call; `0` disables it                                                           |
| `drainDeadlineMs`         | 5000                    | `DRAIN_DEADLINE_MS`               | Budget for one `flush()` or end-of-request drain, retries included; `0` disables it                               |
| `flushOnShutdown`         | `true`                  | `FLUSH_ON_SHUTDOWN`               | Drain from a `register_shutdown_function`                                                                         |
| `finishRequestOnShutdown` | `true`                  | `FINISH_REQUEST`                  | Call `fastcgi_finish_request()` before that drain                                                                 |

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
Laravel:

- Under PHP-FPM or `artisan serve`, the provider drains after the response.
- Under Octane, add `PartnerApi\Logger\Logger::class` to `warm` in
  `config/octane.php`: one logger then serves the worker, the terminating
  callback drains it after each request, and the request scope API is what
  keeps requests apart.
- In queue workers nothing drains per job: call `flush()` at the end of each
  job, or from `Queue::after()` / `Queue::failing()`.

A POST carries at most 1000 entries
and 1 MB of log lines.

### What this costs your request

PHP has no event loop, so delivery is synchronous and bounded twice:

- **A log call** waits only for drain 1, for at most `autoDrainTimeoutMs`
  (1 s): one attempt per batch, no backoff, and anything undelivered goes back
  on the buffer. A failed drain switches drain 1 off until a later drain
  delivers everything, so an ingest outage costs a request one
  `autoDrainTimeoutMs` at most.
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

The event also carries `message`, `entryCount`, `droppedTotal`, and where they
apply `status`, `attempts`, `retryable` and `cause`.

### Counters

```php
$logger->stats();
// ['buffered' => 0, 'delivered' => 480, 'dropped' => 0, 'upstreamDropped' => 0]
```

`upstreamDropped` counts upstream calls dropped for want of a response line.

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

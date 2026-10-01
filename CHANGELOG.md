<!-- doc-type: reference -->

# Changelog

## 2.1.0

Request scopes and the upstream call trail, bringing the PHP SDK to parity
with `@partner-api/logger` 3.1.0, and a redaction helper that meets the shared
PII corpus. It no longer needs ext-bcmath. Log lines are unchanged for code
that never opens a scope or records a call; two additions a strict test double
can notice are under Changed.

### Added

- **Upstream call trail on the response line (PAPI-5337 parity, FLT-1301).**
  `$logger->upstream($call)` records an upstream call — `name`, `method`,
  `url`, `durationMs` and the optional `status`, `requestId`, `errorCode`,
  `message` and `attempt` — against the current request, and `logResponse()`
  ships the request's calls as `upstream: [...]` on the `Outgoing response`
  line, in call order, then clears them. `$logger->upstreamMiddleware($name)`
  returns a Guzzle middleware — the PHP counterpart of the
  TypeScript SDK's `wrapFetch` — that records every request sent through it:
  method, URL, status (an HTTP error status whether or not `http_errors`
  raises it), duration, the vendor request id from the same default headers as
  TypeScript (`UpstreamTrail::DEFAULT_REQUEST_ID_HEADERS`, extended by
  `requestIdHeaders`), and for a transport error no `status`, an `errorCode`
  (`CURLE_COULDNT_CONNECT`, …) and a `message`, with the exception rethrown
  unchanged. The middleware records `attempt` only when it sits inside Guzzle's
  `Middleware::retry()`. Capped at 20 calls / 8 KB per response (measured on
  the line's own JSON encoding), oldest dropped, marked `_upstreamTruncated` /
  `_upstreamDropped`; long fields are cut to ingest's limits.
  URL query strings, fragments and userinfo are stripped client-side,
  including from a transport error's `message`. Every scope ships its own
  calls first, so a request's pre-flight calls ride on its own response. A
  nested scope opened while its enclosing request is mid-exchange belongs to
  that request: a nested `runWithContext()` hands its calls over when its
  callable returns or throws, and a `child()` is pulled when the request
  responds (at most 1000 children wait on one request; past that the
  longest-waiting one is pulled early). A call with no response line left (its
  top-level `runWithContext()` scope ended) is dropped and reported through
  `onError` (reason `invalid-entry`, at most once a minute, the rest at
  `flush()`), never moved to another request's line, and counted in
  `stats()['upstreamDropped']`. A logger-wide call is warned about once, but
  only when the logger has already logged a logger-wide response — the sign it
  outlives its request — not on every PHP-FPM request. **No change to any
  line when nothing is recorded.** New classes: `UpstreamTrail` (constants
  `MAX_CALLS`, `MAX_BYTES`, `DEFAULT_REQUEST_ID_HEADERS`; `stripUrl()`,
  `normalise()`). Contract: `packages/logger-spec` 1.5.0.
- **Per-request context isolation (PAPI-5336 parity, FLT-1301).**
  `$logger->runWithContext($context, $fn)` runs `$fn` inside its own context
  scope and returns what it returns; `$logger->child($context)` returns a
  scope-bound `Logger` sharing this one's buffer, transport, counters and
  `onError`. Inside a scope, `setContext()` and the fields
  `logRequest()`/`logResponse()` set belong to that request only, so a
  long-running worker (Octane, RoadRunner, a queue worker) no longer carries
  one request's `partnerId`, `upstream_integration` or `correlation_id` into
  the next. Scopes nest, and the previous one is restored when `$fn` returns
  and when it throws. `runWithContext()` is ambient for the synchronous
  duration of `$fn` only; a server that interleaves requests in one process
  (Swoole coroutines, fibers) uses `child()` per request. `onError` and the
  end-of-request drain run outside every scope. The `PartnerLogger` facade
  forwards `runWithContext()`, `child()`, `upstream()` and
  `upstreamMiddleware()` to the singleton. **No behaviour change for code that
  never opens a scope** — `setContext()` there stays logger-wide, which is
  conformant for PHP-FPM.

### Changed

- **`stats()` returns a fourth key, `upstreamDropped`.** Code comparing the
  whole array (`assertSame([...], $logger->stats())`) must add it.
- **Every ingest POST carries one more Guzzle request option,
  `partner_api_logger_internal => true`** (`Logger::INTERNAL_REQUEST_OPTION`),
  so an `upstreamMiddleware()` on a client the logger also uses never records
  the logger's own POSTs. A test double that asserts the exact options array
  passed to `ClientInterface::request()` must allow it. The wire is unchanged:
  Guzzle does not send unknown options.
- **`redactPII` meets the shared redaction corpus (FLT-1191).** The PHP helper
  was a third hand-kept copy of the ruleset that had fallen behind
  `@partner-api/logger` 3.1.0. It now carries the same rules, in the same
  order, and `tests/RedactPiiCorpusTest.php` runs it against
  `packages/database/src/pii-redaction-corpus.json` — the contract the
  platform sanitiser and the JavaScript SDK already meet (PAPI-4859) — so a
  rule added there fails this suite until it is ported here.
- **More is redacted.** Issuer-prefixed credentials are redacted whole: GitHub
  (`ghp_`, `github_pat_`, …), AWS key ids, Slack, Stripe, model-provider,
  Google, npm and GitLab keys, and Partner API `tenant_live_`, `psa_` and
  `papi_admin_` tokens — including one glued to a name by `_` (PAPI-4859). An
  international phone number loses its national digits with its country code
  (`+44 20 7946 0000`, grouped by NBSP or another Unicode space, `&nbsp;` or
  fullwidth digits included) and keeps a status code, date, unit or name
  written after it. A card, token, JWT, IP address or Bearer header straight
  after a JSON string escape (`\n`) is redacted, a JWT after a dash is
  redacted, and with `redactUuids` a UUID glued to a word is redacted.
- **Keys, not only values (PAPI-4960).** An array key that is itself PII — a
  phone number, an email address, a card, a credential — is redacted like a
  value. Two keys that redact alike keep both values, the second under `#2`,
  the third `#3`. An identifier-shaped key (`token_expires_at`, a UUID, an IP
  address) stays readable, and a sensitive key still keeps its key and loses
  its value. A `__proto__` key is dropped, as the JavaScript helper drops it
  (PAPI-4797), so both log the same thing.
- **Less is redacted.** A signed count or duration (`+12`, `+30s`,
  `delta=+200ms`) is no longer a phone number. A URL's host is never
  redacted, and a long REST path keeps its words and loses only its id-shaped
  pieces (`/api/v1/customers/[ENCODED_KEY_REDACTED]/orders`) where it used to
  go whole with the host's TLD (FLT-1107). A base64 run holding `/`, `+` or
  `=` padding still goes whole, and a URL an earlier rule left unparseable
  still loses its query.
- **Linear time and memory.** Every rule runs in time and memory linear in
  its input, with PCRE's JIT on or off: a 1 MB string of any of the shapes
  that make a backtracking rule quadratic is redacted in under a second and
  under 48 MB of peak memory at the default 128 MB `memory_limit`
  (`tests/RedactPiiLinearTimeTest.php`).
- **No fail-open.** A PCRE error — a backtrack or JIT stack limit — used to
  return the string it was redacting unchanged (`preg_replace(...) ?? $text`).
  Now that string comes back as `[REDACTION_FAILED]`
  (`RedactPii::REDACTION_FAILED`), whole, and a key that fails becomes
  `[REDACTION_FAILED]` too. The helper still never throws: it runs on logging
  paths that must not break the request they describe.

### Fixed

- **No longer needs ext-bcmath** (FLT-1306). The entry timestamp was built
  with `bcmul()`, which composer.json never required and the official
  `php:*-cli` images do not ship, so on such a build every log call failed
  while building its entry and was dropped as `invalid-entry` (or, in
  `MODE_DIRECT`, threw `LoggerException`) — nothing reached ingest. It is now
  built with plain string operations, and at the default `bcmath.scale` of 0
  the `timestamp` on the wire is byte-identical for every value a
  `timestampProvider` returned that bcmath accepted — an int, a float such as
  an uncast `microtime(true) * 1000`, a numeric string, or a `Stringable` such
  as a `Brick\Math` number. A provider returning `null`, a bool or an empty
  string, never a real timestamp, is now reported as `invalid-entry` rather
  than sent as a 1970 timestamp.
- **No fractional timestamp when `bcmath.scale` is set** (FLT-1306). With a
  non-zero `bcmath.scale` ini the timestamp went out as e.g.
  `"1700000000000000000.00"`, which ingest rejects with a 400 — losing the
  whole batch it was in. The timestamp is now always a whole number of
  nanoseconds, whatever that ini says.

## 2.0.0

Buffered, non-throwing delivery (PAPI-3672), bringing the PHP SDK to parity
with `@partner-api/logger` 3.0.0. Telemetry can no longer break or fail the
partner request it describes, and what it can cost that request is bounded and
configurable: **at most `autoDrainTimeoutMs` (1 s) per request** on the request
path, and **at most `drainDeadlineMs` (5 s)** for the drain that runs after the
response has been sent. See "What this costs your request" in the readme.

### Breaking

- **Log methods never throw.** `info` / `warn` / `error` / `debug` /
  `logRequest` / `logResponse` append to an in-process buffer and return
  immediately. A `catch (LoggerException $e)` around them no longer fires;
  delivery failures are handed to the new `onError` callable instead.
- **"The call returned" no longer means "delivered."** The buffer drains when
  it reaches `batchSize`, when you call `flush()`, and from a
  `register_shutdown_function` at the end of the request — after
  `fastcgi_finish_request()` where the SAPI provides it, so the client already
  has its response. Call `flush()` explicitly wherever entries must have landed
  before the process moves on (a worker loop, a long-running command).
- **One POST carries many entries.** Batches are grouped by API key, level,
  `partnerId` and upstream attribution, and capped at the ingest limit of 1000
  entries per request and 1 MB of log line. The per-entry wire format is
  byte-identical to 1.2.0.
- **Failed batches are retried** — network faults, 408, 429 and 5xx, with
  equal-jitter exponential backoff, up to `maxRetries` (default 3). Any other
  4xx is dropped without a retry. `['maxRetries' => 0]` restores
  single-attempt behaviour. Retries run only in `flush()` and the
  end-of-request drain, never on the request path.
- **Drains are time-bounded.** The `batchSize` drain is the only one a caller
  waits on, and it is best-effort: one attempt on `autoDrainTimeoutMs`
  (default 1000), no backoff sleep, and a retryable failure hands the chunk
  back to the buffer rather than retrying inline. It then latches off for the
  rest of the request, so N log calls cannot cost N timeouts.
  `flush()` / `shutdown()` / the end-of-request drain are bounded as a whole
  by `drainDeadlineMs` (default 5000), with each attempt clamped to what is
  left of it. This matters on PHP-FPM, where the shutdown drain runs inside
  the request that already answered the client and therefore counts against
  `request_terminate_timeout`. `['drainDeadlineMs' => 0]` removes the bound.
- **Requests now carry a 5 s deadline** (`requestTimeoutMs`), **including the
  `/metrics` POST**. Guzzle's default is no timeout at all, which let a
  blackholed ingest hang the flush — and, in 1.x, the caller's request with it.
  A metrics call that used to take 8 s and succeed now raises
  `LoggerException`. `['requestTimeoutMs' => 0]` restores the old unbounded
  behaviour.
- **An unknown constructor option — or an unknown `mode` — raises.** A typo in
  `$options` would otherwise silently leave a production default in place, and
  a typo'd `PARTNER_API_LOG_MODE` would silently leave a consumer that asked
  for the 1.x profile on the new one, catching nothing where it expects to.

### Added

- `$options` — a fifth constructor argument carrying `mode`, `onError`,
  `maxBufferSize` (1000), `batchSize` (100), `maxRetries` (3),
  `retryBaseDelayMs` (200), `retryMaxDelayMs` (5000), `requestTimeoutMs`
  (5000), `autoDrainTimeoutMs` (1000), `drainDeadlineMs` (5000),
  `flushOnShutdown` (true), `finishRequestOnShutdown` (true), plus
  `sleeper` / `randomizer` / `clock` test seams. The four existing arguments
  are unchanged and still positional/named.
- `PartnerApi\Logger\LoggerErrorEvent` — passed to `onError` for every drop,
  with a `reason` of `flush-failed`, `buffer-overflow`, `invalid-entry` or
  `drain-timeout`, plus `message`, `entryCount`, `droppedTotal` and, where they
  apply, `status`, `attempts`, `retryable` and `cause`. `drain-timeout` is
  PHP-only — the TypeScript SDK drains on an event loop and needs no
  wall-clock budget. `message` is exactly the string 1.x would have thrown, so
  anything matching on that text still matches, and `drain-timeout` keeps the
  same `Failed to send log: …` prefix. The default hook is one `error_log()`
  of it — the same line 1.x wrote to stderr.
- `flush()` (bounded by `drainDeadlineMs`), `shutdown()`, `close()` and
  `stats()` (`['buffered' => …, 'delivered' => …, 'dropped' => …]`).
- `Logger::MODE_DIRECT` — the 1.x profile, synchronous and throwing, for
  consumers that genuinely need a per-call receipt. `examples/log-data.php`
  uses it; application code should not.
- **Direction and upstream attribution** (PAPI-687): the `direction`,
  `upstreamIntegration` and `upstreamBaseUrl` context fields, already in the
  spec and in the TypeScript SDK, now work here too. Additive — omitted from
  the payload when unset.
- Laravel: the service provider registers a `terminating` callback that
  flushes a resolved logger after the response has been sent, which also
  covers long-lived workers (Octane, queues) where per-request shutdown
  functions never fire. Every option above is settable in
  `config/partner-logger.php`.
- A `data` payload `json_encode` cannot serialise (invalid UTF-8, recursion)
  is now reported and dropped. 1.x shipped a literal `false` as the log line.

### Unchanged

- Every existing public signature, including the correlation ID returned by
  `logRequest` — it just no longer costs a round-trip.
- `metric` / `metrics`: still synchronous, still raise `LoggerException` on
  failure. A metric submission is an explicit write the caller is entitled to
  a receipt for. Note that `requestTimeoutMs` applies to them too — see
  Breaking.
- `redactPII` / `RedactPii`.
- The request body, headers and `Failed to send log: …` wording — now carried
  on the reported event rather than on a thrown exception.

## 1.2.0 and earlier

Not tracked in this file. See the git history and the release tags
(`logger-php/1.2.0`, …).

<!-- doc-type: reference -->

# Changelog

## 2.0.0

Buffered, non-throwing delivery (PAPI-3672), bringing the PHP SDK to parity
with `@partner-api/logger` 3.0.0. Telemetry can no longer break, slow or fail
the partner request it describes.

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
  single-attempt behaviour.
- **Requests now carry a 5 s deadline** (`requestTimeoutMs`). Guzzle's default
  is no timeout at all, which let a blackholed ingest hang the flush — and, in
  1.x, the caller's request with it. `['requestTimeoutMs' => 0]` restores the
  old unbounded behaviour.
- **An unknown constructor option raises.** A typo in `$options` would
  otherwise silently leave a production default in place.

### Added

- `$options` — a fifth constructor argument carrying `mode`, `onError`,
  `maxBufferSize` (1000), `batchSize` (100), `maxRetries` (3),
  `retryBaseDelayMs` (200), `retryMaxDelayMs` (5000), `requestTimeoutMs`
  (5000), `flushOnShutdown` (true), `finishRequestOnShutdown` (true), plus
  `sleeper` / `randomizer` test seams. The four existing arguments are
  unchanged and still positional/named.
- `PartnerApi\Logger\LoggerErrorEvent` — passed to `onError` for every drop,
  with a `reason` of `flush-failed`, `buffer-overflow` or `invalid-entry`, plus
  `message`, `entryCount`, `droppedTotal` and, where they apply, `status`,
  `attempts`, `retryable` and `cause`. `message` is exactly the string 1.x
  would have thrown, so anything matching on that text still matches. The
  default hook is one `error_log()` of it — the same line 1.x wrote to stderr.
- `flush()`, `shutdown()`, `close()` and `stats()`
  (`['buffered' => …, 'delivered' => …, 'dropped' => …]`).
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
  a receipt for.
- `redactPII` / `RedactPii`.
- The request body, headers and `Failed to send log: …` wording — now carried
  on the reported event rather than on a thrown exception.

## 1.2.0 and earlier

Not tracked in this file. See the git history and the release tags
(`logger-php/1.2.0`, …).

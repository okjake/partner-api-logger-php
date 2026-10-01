<?php

namespace PartnerApi\Logger;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;

/**
 * Sends structured logs and metrics to the Partner API ingest service.
 *
 * Since 2.0.0 the logger implements the **buffered** delivery profile of
 * `packages/logger-spec/spec.md`: `info()` / `warn()` / `error()` / `debug()` /
 * `logRequest()` / `logResponse()` append to an in-process buffer and **never
 * throw**. Nothing about our ingest — an outage, a slow round-trip, a 500 —
 * can any longer fail the partner request the log describes.
 *
 * PHP has no event loop, so a buffered SDK needs an explicit end-of-request
 * drain. There are three:
 *
 * 1. `flush()` — deliver what is buffered at call time. Always available.
 * 2. `batchSize` — reaching it drains automatically (default 100 entries).
 * 3. A `register_shutdown_function` hook, armed on the first buffered entry.
 *    It calls `fastcgi_finish_request()` first where the SAPI provides it, so
 *    the client already has its response before a single byte goes to ingest.
 *
 * Under Laravel the service provider additionally registers a `terminating`
 * callback, which runs earlier than the shutdown hook and works on long-lived
 * workers (Octane, queues) where per-request shutdown functions never fire.
 *
 * **What a caller can be made to wait for.** Only (2) runs inside a log call,
 * and it is deliberately cheap: one attempt per batch, the drain *as a whole*
 * bounded by `autoDrainTimeoutMs` (default 1000 ms), no backoff sleep, and any
 * chunk it could not deliver — refused for a retryable reason, or never
 * reached before the budget ran out — goes back on the buffer rather than
 * being retried on the request path. A failed auto-drain then latches off for
 * the rest of the request, so N log calls cannot cost N timeouts. So a single
 * log call blocks for at most `autoDrainTimeoutMs`, and a whole request for at
 * most that once. The budget has to cover the whole drain and not just one
 * attempt: a drain posts one request per (apiKey, labels, upstream) group, so
 * a per-attempt bound would still let a request that logged at four levels
 * pay four timeouts inside a single `info()`.
 *
 * (1) and (3) carry the retries, and are bounded as a whole by
 * `drainDeadlineMs` (default 5000 ms). That matters most for (3): it runs
 * inside the same FPM request as the response it already sent, so an
 * unbounded drain would be killed by `request_terminate_timeout` mid-flight.
 * Keep `drainDeadlineMs` well under that setting. Entries still undelivered
 * when the budget runs out are reported as `drain-timeout` and dropped.
 *
 * The pre-2.0 synchronous behaviour — POST on the call, raise on failure — is
 * still reachable with `['mode' => Logger::MODE_DIRECT]`.
 *
 * `metric()` / `metrics()` are deliberately NOT buffered: a metric submission
 * is an explicit write the caller is entitled to a receipt for, so it still
 * posts synchronously and still raises `LoggerException` on failure.
 *
 * **Request scopes (PAPI-5336, FLT-1301).** `setContext()` with no scope open
 * is logger-wide, which is conformant for PHP-FPM and CLI: one request per
 * process. A long-running worker (Octane, RoadRunner, Swoole, a queue worker)
 * serves many requests from one logger, so `runWithContext()` and `child()`
 * give each one its own context and upstream trail. See `RequestScope`.
 */
class Logger
{
    /**
     * The `json_encode` flags every log line is written with. The upstream
     * trail's byte cap measures with the same flags, so it counts exactly the
     * bytes the trail occupies on the wire.
     *
     * @internal
     */
    public const LINE_JSON_FLAGS = JSON_UNESCAPED_SLASHES;

    /**
     * A Guzzle request option the logger sets on its own POSTs to ingest, so
     * an `upstreamMiddleware()` on a client that also serves as the logger's
     * transport never records them as the caller's upstream calls.
     *
     * @internal
     */
    public const INTERNAL_REQUEST_OPTION = 'partner_api_logger_internal';

    /** Log calls buffer and report failures to `onError`. The default. */
    public const MODE_BUFFERED = 'buffered';

    /** Log calls POST synchronously and raise `LoggerException` on failure. */
    public const MODE_DIRECT = 'direct';

    /**
     * The ingest service rejects a `/logs` POST carrying more than this many
     * entries ("Maximum 1000 entries per request"). A drain with more than
     * this queued splits into several POSTs rather than losing the overflow.
     */
    private const MAX_ENTRIES_PER_REQUEST = 1000;

    /**
     * Soft cap on the log-line bytes in one request. Ingest's body parser
     * accepts 5 MB; 1 MB leaves ample headroom for JSON escaping and the
     * labels envelope, and keeps a single failed batch from costing much.
     */
    private const MAX_REQUEST_LINE_BYTES = 1000000;

    private const DEFAULT_BATCH_SIZE = 100;
    private const DEFAULT_MAX_BUFFER_SIZE = 1000;
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_RETRY_BASE_DELAY_MS = 200;
    private const DEFAULT_RETRY_MAX_DELAY_MS = 5000;
    private const DEFAULT_REQUEST_TIMEOUT_MS = 5000;
    private const DEFAULT_AUTO_DRAIN_TIMEOUT_MS = 1000;
    private const DEFAULT_DRAIN_DEADLINE_MS = 5000;

    /** At most one "upstream calls dropped" report per this window (TS: the same). */
    private const UPSTREAM_LOST_REPORT_INTERVAL_MS = 60000;

    /**
     * At most this many nested scopes wait, holding calls, to be pulled by one
     * request (TS: the same). In PHP a nested `runWithContext()` scope always
     * ends — and hands its calls over — before the request around it can, so
     * only `child()` loggers ever wait here: a worker whose app-level scope
     * logged a request at boot and will respond at shutdown would otherwise
     * retain every discarded child that recorded a call. Past the bound the
     * longest-waiting child's calls join the request's trail early; the line
     * that ships is the same.
     */
    private const MAX_WAITING_HELPERS = 1000;

    /**
     * Which drain is running, which is what decides how much time the caller
     * is allowed to spend.
     *
     * - `DIRECT`  — `MODE_DIRECT`: one attempt, raises at the call site.
     * - `AUTO`    — the `batchSize` trigger, running *inside* a log call. One
     *   attempt, its own short timeout, no backoff sleep; a still-retryable
     *   chunk goes back on the buffer for `FULL` to deal with later.
     * - `FULL`    — `flush()` / `shutdown()` / the end-of-request hook. Retries,
     *   bounded as a whole by `drainDeadlineMs`.
     */
    private const DRAIN_DIRECT = 'direct';
    private const DRAIN_AUTO = 'auto';
    private const DRAIN_FULL = 'full';

    /** What became of one chunk. */
    private const OUTCOME_DELIVERED = 'delivered';
    private const OUTCOME_DROPPED = 'dropped';
    private const OUTCOME_REQUEUE = 'requeue';
    private const OUTCOME_ABANDONED = 'abandoned';

    /**
     * Recognised `$options` keys. A typo in a constructor option would
     * otherwise silently leave the default in place — for `maxRetries` or
     * `maxBufferSize` that is a production behaviour change nobody would
     * notice, so an unknown key is a hard error at construction time (never on
     * the log path).
     */
    private const KNOWN_OPTIONS = [
        'mode',
        'onError',
        'maxBufferSize',
        'batchSize',
        'maxRetries',
        'retryBaseDelayMs',
        'retryMaxDelayMs',
        'requestTimeoutMs',
        'autoDrainTimeoutMs',
        'drainDeadlineMs',
        'flushOnShutdown',
        'finishRequestOnShutdown',
        'sleeper',
        'randomizer',
        'clock',
    ];

    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-tenant-token',
        'proxy-authorization',
    ];

    private string $tenantToken;
    private string $baseUrl;
    private ClientInterface $httpClient;

    /**
     * What `setContext()` writes to outside any request scope: the logger-wide
     * context, exactly as before scopes existed.
     */
    private RequestScope $rootScope;

    /**
     * The scope open right now: the innermost `runWithContext()` on the call
     * stack, or the scope a `child()` logger bound for the duration of one of
     * its calls. Null means the root.
     *
     * One variable serves both, because PHP has no `await`: the TypeScript SDK
     * needs `AsyncLocalStorage` to carry a scope across suspended work, while
     * here every scope lasts exactly as long as a synchronous call. It is NOT
     * fiber- or coroutine-aware — a server that interleaves requests inside
     * one process (Swoole coroutines, AMPHP/ReactPHP fibers) must use
     * `child()` per request, which never reads this.
     */
    private ?RequestScope $currentScope = null;

    /** Orders trail entries by when each call STARTED, across all scopes. */
    private int $upstreamSeq = 0;

    /** Upstream calls dropped for want of a response line. */
    private int $upstreamLostTotal = 0;
    private int $upstreamLostUnreported = 0;
    private ?float $upstreamLostReportedAt = null;

    /** Logger-wide `logResponse()` lines shipped — see reportRootUpstream(). */
    private int $rootResponses = 0;
    private bool $reportedRootUpstream = false;

    /** @var callable(): int */
    private $timestampProvider;

    /** @var callable(LoggerErrorEvent): void */
    private $onError;

    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(): float */
    private $randomizer;

    /** @var callable(): float Monotonic milliseconds, for drain budgets. */
    private $clock;

    private string $mode;
    private int $maxBufferSize;
    private int $batchSize;
    private int $maxRetries;
    private int $retryBaseDelayMs;
    private int $retryMaxDelayMs;
    private int $requestTimeoutMs;
    private int $autoDrainTimeoutMs;
    private int $drainDeadlineMs;
    private bool $flushOnShutdown;
    private bool $finishRequestOnShutdown;

    /** @var list<array{apiKey: string, labels: array<string, string>, root: array<string, string>, entry: array{timestamp: string, line: string}}> */
    private array $buffer = [];

    private bool $shutdownArmed = false;
    private bool $draining = false;

    /**
     * Latched when a request-path drain fails, so the *next* log call past
     * `batchSize` does not pay another `autoDrainTimeoutMs` to discover the
     * same outage. Cleared by a full drain that delivers everything.
     */
    private bool $autoDrainSuppressed = false;

    private int $deliveredTotal = 0;
    private int $droppedTotal = 0;

    /**
     * @param array{
     *     mode?: string,
     *     onError?: callable(LoggerErrorEvent): void,
     *     maxBufferSize?: int,
     *     batchSize?: int,
     *     maxRetries?: int,
     *     retryBaseDelayMs?: int,
     *     retryMaxDelayMs?: int,
     *     requestTimeoutMs?: int,
     *     autoDrainTimeoutMs?: int,
     *     drainDeadlineMs?: int,
     *     flushOnShutdown?: bool,
     *     finishRequestOnShutdown?: bool,
     *     sleeper?: callable(int): void,
     *     randomizer?: callable(): float,
     *     clock?: callable(): float
     * } $options
     *
     * - `mode` — `Logger::MODE_BUFFERED` (default) or `Logger::MODE_DIRECT`.
     * - `onError` — receives a {@see LoggerErrorEvent} for every drop. Defaults
     *   to one `error_log()` of the message, which is what the direct profile
     *   wrote to stderr. Throwing from the hook is swallowed.
     * - `maxBufferSize` — entries held before the oldest are dropped to make
     *   room (default 1000). Bounds memory when ingest is unreachable.
     * - `batchSize` — buffered entries that trigger an automatic drain
     *   (default 100). Clamped to `maxBufferSize` and to the ingest maximum of
     *   1000. `0` disables the size trigger, leaving `flush()` and the
     *   end-of-request hook as the only drains — the right setting for a
     *   request handler that wants exactly one POST at the end.
     * - `maxRetries` — retries after the first attempt, per batch (default 3).
     *   Only network faults, 408, 429 and 5xx are retried; any other 4xx is a
     *   bad request and is dropped immediately. Applies to `flush()` and the
     *   end-of-request drain only: the `batchSize` drain runs inside a log call
     *   and never retries inline, and direct mode never retries at all.
     * - `retryBaseDelayMs` / `retryMaxDelayMs` — equal-jitter backoff window
     *   (defaults 200 / 5000).
     * - `requestTimeoutMs` — per-request deadline (default 5000; `0` disables).
     *   Guzzle has no timeout by default, so without this a blackholed ingest
     *   hangs the flush — and in direct mode, the request itself.
     * - `autoDrainTimeoutMs` — total wall-clock budget for the `batchSize`
     *   drain, which is the only drain a caller waits on (default 1000; `0`
     *   disables the bound). This is the SDK's whole budget for one log call,
     *   across every group and chunk: one attempt each, no backoff sleep, and
     *   anything it could not deliver in time goes back on the buffer for the
     *   end-of-request drain rather than being retried on the request path.
     * - `drainDeadlineMs` — total wall-clock budget for one `flush()` /
     *   `shutdown()` / end-of-request drain, across every group, chunk, retry
     *   and backoff (default 5000; `0` disables the bound). Whatever is still
     *   undelivered when it runs out is reported as `drain-timeout` and
     *   dropped. Bounds the drain that runs inside the FPM request after
     *   `fastcgi_finish_request()` — keep it well under the pool's
     *   `request_terminate_timeout`, or FPM kills the worker mid-drain.
     * - `flushOnShutdown` — arm the `register_shutdown_function` drain
     *   (default true).
     * - `finishRequestOnShutdown` — call `fastcgi_finish_request()` before that
     *   drain where the SAPI has it (default true), so delivery happens after
     *   the client already has its response.
     * - `sleeper` / `randomizer` / `clock` — backoff and deadline seams for
     *   tests. `clock` returns monotonic milliseconds.
     */
    public function __construct(
        string $tenantToken,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        ?callable $timestampProvider = null,
        array $options = [],
    ) {
        $unknown = array_diff(array_keys($options), self::KNOWN_OPTIONS);
        if ($unknown !== []) {
            throw new LoggerException(
                'Unknown Logger option(s): ' . implode(', ', $unknown)
                . '. Known options: ' . implode(', ', self::KNOWN_OPTIONS)
            );
        }

        $this->rootScope = new RequestScope([], null);
        $this->tenantToken = $tenantToken;
        $this->baseUrl = $baseUrl ?? 'https://ingest.partnerapi.com';
        $this->httpClient = $httpClient ?? new Client();
        $this->timestampProvider = $timestampProvider ?? fn () => (int) (microtime(true) * 1000);

        // Same reasoning as the unknown-key check above, and the same blast
        // radius: `PARTNER_API_LOG_MODE=diret` silently coercing to buffered
        // would leave a consumer that asked for the 1.x profile quietly on the
        // new one, catching nothing where it expects to catch.
        $mode = $options['mode'] ?? self::MODE_BUFFERED;
        if ($mode !== self::MODE_BUFFERED && $mode !== self::MODE_DIRECT) {
            throw new LoggerException(sprintf(
                'Unknown Logger mode: %s. Known modes: %s, %s',
                is_scalar($mode) ? (string) $mode : get_debug_type($mode),
                self::MODE_BUFFERED,
                self::MODE_DIRECT,
            ));
        }
        $this->mode = $mode;

        $this->onError = $options['onError'] ?? static function (LoggerErrorEvent $event): void {
            error_log($event->message);
        };

        $this->maxBufferSize = max(1, (int) ($options['maxBufferSize'] ?? self::DEFAULT_MAX_BUFFER_SIZE));

        // A drain trigger the buffer can never reach would mean dropping
        // entries that were never even attempted, so the buffer size is the
        // real ceiling — as is the ingest per-request maximum.
        $batchSize = (int) ($options['batchSize'] ?? self::DEFAULT_BATCH_SIZE);
        $this->batchSize = $batchSize <= 0
            ? 0
            : min($batchSize, self::MAX_ENTRIES_PER_REQUEST, $this->maxBufferSize);

        $this->maxRetries = max(0, (int) ($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES));
        $this->retryBaseDelayMs = max(0, (int) ($options['retryBaseDelayMs'] ?? self::DEFAULT_RETRY_BASE_DELAY_MS));
        $this->retryMaxDelayMs = max(
            $this->retryBaseDelayMs,
            (int) ($options['retryMaxDelayMs'] ?? self::DEFAULT_RETRY_MAX_DELAY_MS),
        );
        $this->requestTimeoutMs = max(0, (int) ($options['requestTimeoutMs'] ?? self::DEFAULT_REQUEST_TIMEOUT_MS));
        $this->autoDrainTimeoutMs = max(
            0,
            (int) ($options['autoDrainTimeoutMs'] ?? self::DEFAULT_AUTO_DRAIN_TIMEOUT_MS),
        );
        $this->drainDeadlineMs = max(0, (int) ($options['drainDeadlineMs'] ?? self::DEFAULT_DRAIN_DEADLINE_MS));
        $this->flushOnShutdown = (bool) ($options['flushOnShutdown'] ?? true);
        $this->finishRequestOnShutdown = (bool) ($options['finishRequestOnShutdown'] ?? true);

        $this->sleeper = $options['sleeper'] ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
        $this->randomizer = $options['randomizer'] ?? static fn (): float => mt_rand() / mt_getrandmax();

        // hrtime() is monotonic: a drain budget must not be moved by an NTP
        // step or a leap second the way microtime() can be.
        $this->clock = $options['clock'] ?? static fn (): float => hrtime(true) / 1e6;
    }

    /**
     * Merges `$fields` into the ACTIVE scope's context.
     *
     * Inside {@see Logger::runWithContext()} (or on a {@see Logger::child()})
     * that is the request's own scope, invisible to every other request.
     * Outside any scope it is logger-wide, as it always was — right for
     * PHP-FPM, wrong on a long-running worker, which should open a scope per
     * request before calling this.
     *
     * @param array<string, mixed> $fields
     */
    public function setContext(array $fields): void
    {
        $scope = $this->activeScope();
        $scope->context = array_merge($scope->context, $fields);
    }

    /**
     * Runs `$fn` inside a new request scope (PAPI-5336) and returns what it
     * returns.
     *
     * The scope starts as a copy of the current context merged with
     * `$context`. Everything the request does to context while `$fn` runs —
     * `setContext()`, and the fields `logRequest()` / `logResponse()` set —
     * and every upstream call it records stays in that scope, so the next
     * request on the same long-lived logger starts clean. Scopes nest. The
     * previous scope is restored when `$fn` returns AND when it throws (the
     * exception propagates unchanged).
     *
     * `$fn` receives a logger bound to the scope (`fn (Logger $scoped) => …`),
     * which is the same scope `$this` resolves to while `$fn` runs.
     *
     * The scope ENDS when `$fn` returns or throws. Upstream calls it still
     * holds then go to the request it was opened under, if that request was
     * mid-exchange; with none, they are dropped and reported through
     * `onError` (see `stats()['upstreamDropped']`). So log the response
     * inside `$fn`, not from a terminate hook that runs after it.
     *
     * Ambient for the synchronous duration of `$fn` only — not across
     * coroutines or fibers. On a server that interleaves requests in one
     * process, use {@see Logger::child()} per request.
     *
     * ```php
     * // Octane / RoadRunner middleware — once, at the edge of the request.
     * return $logger->runWithContext(
     *     ['partnerId' => $partnerId],
     *     fn () => $next($request),
     * );
     * ```
     *
     * @template T
     * @param array<string, mixed> $context
     * @param callable(Logger): T  $fn
     * @return T
     */
    public function runWithContext(array $context, callable $fn): mixed
    {
        $scope = $this->openScope($context);
        $outer = $this->currentScope;
        $this->currentScope = $scope;

        try {
            return $fn($this->bindScope($scope));
        } finally {
            $this->currentScope = $outer;
            $this->endScope($scope);
        }
    }

    /**
     * Returns a logger bound to a new scope (PAPI-5336): a copy of the current
     * context merged with `$context`. Its `setContext()`, request/response
     * logging and upstream calls touch only that scope; it shares this
     * logger's buffer, transport, counters, `onError`, `flush()` and
     * `stats()`.
     *
     * Needs nothing from the runtime, so it is the portable way to isolate a
     * request — including on coroutine servers, where `runWithContext()`'s
     * ambient scope cannot follow a request. A child never ends: create one
     * per request and drop it afterwards.
     *
     * @param array<string, mixed> $context
     */
    public function child(array $context = []): Logger
    {
        // A child records its calls in its own trail. Opened while the
        // request above is mid-exchange, it belongs to that request, which
        // pulls its calls when it responds or ends unless the child becomes a
        // request itself. Otherwise it is top-level and clears after each
        // response. Context is a per-scope copy either way.
        return $this->bindScope($this->openScope($context));
    }

    /**
     * Records one upstream call against the current request (PAPI-5337).
     *
     * `$call` has exactly the spec's keys: `name`, `method`, `url`, `status`
     * (omit it for a network error), `durationMs`, and optionally
     * `requestId`, `errorCode`, `message`, `attempt`. The call ships as an
     * entry of `upstream: [...]` on this request's next `logResponse()` line —
     * and on no other line — in the order calls were made; then the trail is
     * cleared. Capped at 20 calls / 8 KB, oldest dropped and the line marked.
     *
     * Never throws. A call ingest would reject (no `name`/`method`/`url`, a
     * non-HTTP `status`, a bad `durationMs`) is dropped and reported through
     * `onError`. The URL's query string, fragment and userinfo are stripped
     * before it is stored.
     *
     * Outside any scope the call is held logger-wide and ships on the next
     * `logResponse()` made outside a scope — correct for PHP-FPM, where that
     * is this request's response.
     *
     * @param array<string, mixed> $call
     */
    public function upstream(array $call): void
    {
        try {
            $this->recordUpstream($this->activeScope(), $this->upstreamSeq++, $call);
        } catch (\Throwable $e) {
            $this->reportUpstreamFailure($e);
        }
    }

    /**
     * A Guzzle middleware that records every call made through it as an
     * upstream call named `$name` (PAPI-5337) — the PHP counterpart of the
     * TypeScript SDK's `wrapFetch()`. Guzzle composes behaviour on a
     * `HandlerStack` rather than by wrapping a function, so this hands back
     * the middleware to push:
     *
     * ```php
     * $stack = HandlerStack::create();
     * $stack->push($logger->upstreamMiddleware('stripe'));
     * $stripe = new Client(['handler' => $stack]);
     * ```
     *
     * Each call records `method`, `url` (query string stripped), `status`,
     * `durationMs` and the vendor's request id — the first of
     * `$options['requestIdHeaders']` and then
     * {@see UpstreamTrail::DEFAULT_REQUEST_ID_HEADERS} present on the
     * response. An HTTP error status is recorded as that status, whether
     * Guzzle's `http_errors` turns it into an exception or not; the response
     * body is never read. A transport error (DNS, refused, timeout) is
     * recorded with no `status`, an `errorCode` and a `message`, and the
     * exception reaches the caller unchanged. `attempt` is recorded when the
     * middleware sits inside Guzzle's `Middleware::retry()` — a `HandlerStack`
     * nests in push order, so push the retry middleware FIRST and this one
     * after it — from the retry counter that middleware passes down; then
     * every attempt is its own call. Anywhere else `attempt` is omitted and
     * a retried request is one call.
     *
     * The call is attributed to the scope active when the request is SENT, so
     * one client built at boot serves every request correctly under
     * `runWithContext()`; a middleware made from a `child()` always records
     * into that child's scope. Recording never throws into the request: a
     * failure to record is reported through `onError`.
     *
     * @param array{requestIdHeaders?: list<string>} $options
     * @return callable(callable): callable
     *
     * @throws LoggerException On an unknown option — at setup, never per call.
     */
    public function upstreamMiddleware(string $name, array $options = []): callable
    {
        return $this->makeUpstreamMiddleware($name, $options, null);
    }

    /**
     * @param array<string, mixed> $options
     * @return callable(callable): callable
     *
     * @internal Shared with {@see ScopedLogger}, which binds `$boundScope`.
     */
    protected function makeUpstreamMiddleware(string $name, array $options, ?RequestScope $boundScope): callable
    {
        $unknown = array_diff(array_keys($options), ['requestIdHeaders']);
        if ($unknown !== []) {
            throw new LoggerException(
                'Unknown upstreamMiddleware option(s): ' . implode(', ', $unknown)
                . '. Known options: requestIdHeaders'
            );
        }
        $extra = $options['requestIdHeaders'] ?? [];
        if (!is_array($extra) || array_filter($extra, static fn ($h) => !is_string($h)) !== []) {
            throw new LoggerException('upstreamMiddleware option requestIdHeaders must be a list of header names');
        }

        return new UpstreamMiddleware(
            $name,
            array_values(array_merge($extra, UpstreamTrail::DEFAULT_REQUEST_ID_HEADERS)),
            // Resolved when the request is sent: the scope the caller is in now.
            function () use ($boundScope): array {
                return [$boundScope ?? $this->activeScope(), $this->upstreamSeq++, $this->clockMs()];
            },
            function (RequestScope $scope, int $seq, float $startedMs, array $call): void {
                try {
                    $call['durationMs'] = max(0, (int) round($this->clockMs() - $startedMs));
                    $this->recordUpstream($scope, $seq, $call);
                } catch (\Throwable $e) {
                    $this->reportUpstreamFailure($e);
                }
            },
        );
    }

    /**
     * Runs `$fn` with `$scope` as the active scope, restoring the previous
     * one afterwards whatever happens.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     *
     * @internal Used by {@see ScopedLogger} to run a method in its scope.
     */
    protected function withScope(RequestScope $scope, callable $fn): mixed
    {
        $outer = $this->currentScope;
        $this->currentScope = $scope;
        try {
            return $fn();
        } finally {
            $this->currentScope = $outer;
        }
    }

    /** The scope context reads and writes resolve to right now. */
    private function activeScope(): RequestScope
    {
        return $this->currentScope ?? $this->rootScope;
    }

    /** @param array<string, mixed> $context */
    private function openScope(array $context): RequestScope
    {
        $parent = $this->activeScope();
        $scope = new RequestScope(array_merge($parent->context, $context), $parent);
        // Decided now, never re-decided (ruling 2026-09-26). Opened
        // mid-exchange, the scope belongs to that exchange; opened between
        // exchanges (an app scope after its health check), it is top-level.
        $request = $this->nearestRequest($scope);
        $scope->host = $request !== null && $request->midExchange ? $request : null;

        return $scope;
    }

    private function bindScope(RequestScope $scope): Logger
    {
        return new ScopedLogger($this, $scope);
    }

    /**
     * Runs `$fn` outside every request scope. For logger-internal work that
     * belongs to no request (spec § Per-request context isolation, rule 4):
     * the `onError` hook and the end-of-request drain. A hook that logs on
     * this logger therefore writes a logger-wide line, never one stamped with
     * whichever request happened to trigger the report.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function atRootScope(callable $fn): mixed
    {
        $outer = $this->currentScope;
        $this->currentScope = null;
        try {
            return $fn();
        } finally {
            $this->currentScope = $outer;
        }
    }

    /**
     * The nearest enclosing REQUEST scope (ruling 2026-09-26: the nearest
     * scope above that has called `logRequest` or `logResponse`), or null.
     * The root is never one: it is the logger-wide trail, not a request.
     */
    private function nearestRequest(RequestScope $scope): ?RequestScope
    {
        for ($s = $scope->parent; $s !== null; $s = $s->parent) {
            if ($s->isRequest) {
                return $s;
            }
        }

        return null;
    }

    /**
     * The trail a call recorded in `$scope` right now goes to (rulings
     * 2026-09-26). A scope records its OWN calls in its OWN trail first, so a
     * request's pre-flight calls ship on its own response.
     *
     * - A live scope (root, request, running `runWithContext()`, `child()`):
     *   its own trail. If it is not itself a request and has a host, it
     *   registers as that host's helper, so the host pulls the calls onto its
     *   own line when it responds or ends.
     * - An ENDED scope: its host's destination; with no host, its own trail,
     *   which is closed — the call is reported and dropped, never parked.
     * - A live, non-request scope whose host has ENDED: that host's
     *   destination. A live scope that has become a request keeps its own.
     */
    private function upstreamDestination(RequestScope $scope): UpstreamTrail
    {
        if ($scope === $this->rootScope) {
            return $scope->trail;
        }

        $host = $scope->host;
        if ($scope->ended || (!$scope->isRequest && $host !== null && $host->ended)) {
            return $host !== null ? $this->upstreamDestination($host) : $scope->trail;
        }
        if ($host !== null && !$scope->isRequest) {
            $this->registerHelper($host, $scope);
        }

        return $scope->trail;
    }

    /**
     * A request responds or ends: its helpers' calls join its trail, in
     * start order. A helper whose nearest request is now another scope is
     * handed to that one instead.
     */
    private function pullHelpers(RequestScope $request): void
    {
        foreach ($request->helpers as $id => $helper) {
            // A report raised mid-loop runs user code; never pull twice.
            if (isset($request->helpers[$id])) {
                $this->pullHelper($request, $helper);
            }
        }
    }

    private function pullHelper(RequestScope $request, RequestScope $helper): void
    {
        unset($request->helpers[spl_object_id($helper)]);
        // (A helper that became a request unregistered itself: markRequest.)
        // A scope between the helper and this request has since become a
        // request: the helper's calls are that request's now.
        $nearest = $this->nearestRequest($helper);
        if ($nearest !== null && $nearest !== $request) {
            $helper->host = $nearest;
            $this->registerHelper($nearest, $helper);
            return;
        }
        $this->reportUpstreamLost($helper->trail->handOff($request->trail));
    }

    /**
     * Registers `$helper` to be pulled by `$request`, keeping the registry
     * bounded ({@see self::MAX_WAITING_HELPERS}): past the bound, the scope
     * that has waited longest is pulled now.
     */
    private function registerHelper(RequestScope $request, RequestScope $helper): void
    {
        $request->helpers[spl_object_id($helper)] ??= $helper;
        if (count($request->helpers) <= self::MAX_WAITING_HELPERS) {
            return;
        }
        $oldest = $request->helpers[array_key_first($request->helpers)];
        if ($oldest !== $helper) {
            $this->pullHelper($request, $oldest);
        }
    }

    /**
     * A `runWithContext()` scope's `$fn` returned or threw. What it holds —
     * its helpers' calls included, if it is a request — goes to the request
     * it belongs to; with none, it is reported and dropped.
     *
     * Runs in a `finally`, so it must not throw: it would replace the
     * exception `$fn` is propagating.
     */
    private function endScope(RequestScope $scope): void
    {
        try {
            $scope->ended = true;
            if ($scope->host !== null) {
                unset($scope->host->helpers[spl_object_id($scope)]);
            }
            $this->pullHelpers($scope);
            $destination = $this->upstreamDestination($scope);
            if ($destination !== $scope->trail) {
                $this->reportUpstreamLost($scope->trail->handOff($destination));
            } else {
                $this->reportUpstreamLost($scope->trail->close());
            }
        } catch (\Throwable $e) {
            $this->reportUpstreamFailure($e);
        }
    }

    /**
     * Upstream calls with no request line left to ship on: dropped, and
     * reported through `onError`. Every call is counted; the report itself is
     * rate-limited to one per {@see self::UPSTREAM_LOST_REPORT_INTERVAL_MS}
     * and carries the calls dropped since the last report and in total.
     */
    private function reportUpstreamLost(int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $this->upstreamLostTotal += $count;
        $this->upstreamLostUnreported += $count;
        $now = $this->clockMs();
        if (
            $this->upstreamLostReportedAt !== null
            && $now - $this->upstreamLostReportedAt < self::UPSTREAM_LOST_REPORT_INTERVAL_MS
        ) {
            return;
        }
        $this->reportUnreportedUpstreamLost($now);
    }

    /**
     * Reports whatever the rate limit has held back. Called from
     * reportUpstreamLost() and from `flush()`, so a burst inside one window
     * is still reported in full before the process goes away.
     */
    private function reportUnreportedUpstreamLost(?float $now = null): void
    {
        if ($this->upstreamLostUnreported === 0) {
            return;
        }
        $this->upstreamLostReportedAt = $now ?? $this->clockMs();
        $since = $this->upstreamLostUnreported;
        $this->upstreamLostUnreported = 0;
        // Same wording as the TypeScript SDK up to the cause, which is PHP's.
        $this->report(new LoggerErrorEvent(
            LoggerErrorEvent::REASON_INVALID_ENTRY,
            sprintf(
                'logger.upstream(): dropped %d upstream call%s with no response line left to ship on — %s request scope ended (runWithContext\'s fn returned or threw) with no enclosing request to hand %s to (%d dropped in total)',
                $since,
                $since === 1 ? '' : 's',
                $since === 1 ? 'its' : 'their',
                $since === 1 ? 'it' : 'them',
                $this->upstreamLostTotal,
            ),
            0,
            $this->droppedTotal,
        ));
    }

    /**
     * Normalises and stores one call in the trail `$scope` resolves to.
     *
     * @param array<string, mixed> $call
     */
    private function recordUpstream(RequestScope $scope, int $seq, array $call): void
    {
        $normalised = UpstreamTrail::normalise($call);
        if (is_string($normalised)) {
            $this->report(new LoggerErrorEvent(
                LoggerErrorEvent::REASON_INVALID_ENTRY,
                "logger.upstream(): {$normalised} — call not recorded",
                0,
                $this->droppedTotal,
            ));
            return;
        }

        $destination = $this->upstreamDestination($scope);
        if ($destination === $this->rootScope->trail) {
            $this->reportRootUpstream();
        }
        if (!$destination->record($seq, $normalised)) {
            $this->reportUpstreamLost(1);
        }
    }

    /** Something outside the contract threw while recording (a caller's `clock`, say). */
    private function reportUpstreamFailure(\Throwable $e): void
    {
        $this->report(new LoggerErrorEvent(
            LoggerErrorEvent::REASON_INVALID_ENTRY,
            'logger.upstream(): call could not be recorded: ' . $e->getMessage(),
            0,
            $this->droppedTotal,
            null,
            null,
            null,
            $e,
        ));
    }

    /** Marks the active scope a request (it logs a request or response line). */
    private function markRequest(): RequestScope
    {
        $scope = $this->activeScope();
        if ($scope !== $this->rootScope && !$scope->isRequest) {
            $scope->isRequest = true;
            // Its calls ship on its own line now: nothing above may pull them.
            if ($scope->host !== null) {
                unset($scope->host->helpers[spl_object_id($scope)]);
            }
        }

        return $scope;
    }

    /**
     * The trail `logResponse()` ships: the responding scope's own — its
     * pre-flight calls included — plus whatever its non-request nested scopes
     * are holding (pulled first), cleared so the scope keeps collecting for
     * its next response.
     *
     * @return array{calls: list<array<string, mixed>>, dropped: int}|null
     */
    private function responseTrail(): ?array
    {
        $scope = $this->markRequest();
        if ($scope === $this->rootScope) {
            $this->rootResponses++;
            return $scope->trail->take();
        }
        $this->pullHelpers($scope);
        $scope->midExchange = false;

        return $scope->trail->take();
    }

    /**
     * Once per logger: a call landed on the logger-wide trail of a logger
     * that has ALREADY shipped a logger-wide response.
     *
     * The spec asks for a one-time warning whenever a call is held
     * logger-wide, because on a concurrent server it ships on whichever
     * request responds next. The TypeScript SDK warns on the first such call.
     * In PHP that is the normal, conformant case — one request per PHP-FPM
     * process — so warning there would put a line in every FPM request's
     * error log. A second logger-wide response is what shows this logger
     * outlives its request (an Octane singleton, a queue worker), which is
     * exactly when an unanswered request's calls can land on the next one's
     * line. That is when this warns.
     */
    private function reportRootUpstream(): void
    {
        if ($this->reportedRootUpstream || $this->rootResponses === 0) {
            return;
        }
        $this->reportedRootUpstream = true;
        $this->report(new LoggerErrorEvent(
            LoggerErrorEvent::REASON_INVALID_ENTRY,
            'logger.upstream() / upstreamMiddleware recorded a call outside any request scope on a logger that has already logged a response — it is held on the logger-wide trail (at most 20) and ships on the next logResponse made outside a scope, from whichever request that is. On a long-running worker (Octane, RoadRunner, Swoole, a queue worker), record calls inside a request scope (runWithContext / child); safe to ignore if each request runs in its own process',
            0,
            $this->droppedTotal,
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function info(string $apiKey, string $message, array $data = []): void
    {
        $this->record($apiKey, 'info', $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function warn(string $apiKey, string $message, array $data = []): void
    {
        $this->record($apiKey, 'warn', $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function error(string $apiKey, string $message, array $data = []): void
    {
        $this->record($apiKey, 'error', $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function debug(string $apiKey, string $message, array $data = []): void
    {
        $this->record($apiKey, 'debug', $message, $data);
    }

    /**
     * @param array{method: string, path: string, headers?: array<string, string>, body?: mixed} $request
     * @return string The correlation ID used to pair this request with a response
     */
    public function logRequest(string $apiKey, array $request): string
    {
        // PAPI-5337: this scope is now a request, mid-exchange until it
        // responds — scopes opened under it from here belong to its response.
        $this->markRequest()->midExchange = true;
        $headers = $request['headers'] ?? [];
        $correlationId = $headers['x-correlation-id'] ?? Uuid::uuid4()->toString();

        // `$request` is the caller's array. A missing `method` / `path` is only
        // a warning here — until the host framework promotes warnings to
        // `ErrorException`, which Laravel does, at which point a malformed
        // argument escapes a log call that promises never to throw.
        try {
            $this->setContext([
                'method' => $request['method'],
                'path' => $request['path'],
                'requestId' => $headers['x-request-id'] ?? null,
                'correlationId' => $correlationId,
                'statusCode' => null,
                'duration' => null,
            ]);

            $data = [
                'method' => $request['method'],
                'path' => $request['path'],
                'headers' => $this->redactHeaders($headers),
                'correlation_id' => $correlationId,
            ];

            if (array_key_exists('body', $request)) {
                $data['body'] = $request['body'];
            }
        } catch (\Throwable $e) {
            $this->reject('Failed to send log: request could not be described: ' . $e->getMessage());

            // The caller still gets a usable ID to pair its response with —
            // losing the entry must not also lose the correlation.
            return $correlationId;
        }

        $this->info($apiKey, 'Incoming request', $data);

        return $correlationId;
    }

    /**
     * @param array{statusCode: int, headers?: array<string, string>, body?: mixed, duration: int|float, correlationId: string} $response
     */
    public function logResponse(string $apiKey, array $response): void
    {
        // See `logRequest()`: a malformed `$response` must be reported, not
        // raised at a caller that was promised a non-throwing log call.
        try {
            $this->setContext([
                'statusCode' => $response['statusCode'],
                'duration' => $response['duration'],
            ]);

            $data = [
                'status_code' => $response['statusCode'],
                'headers' => isset($response['headers']) ? $this->redactHeaders($response['headers']) : null,
                'body' => $response['body'] ?? null,
                'duration_ms' => $response['duration'],
                'correlation_id' => $response['correlationId'],
            ];
        } catch (\Throwable $e) {
            $this->reject('Failed to send log: response could not be described: ' . $e->getMessage());

            return;
        }

        // PAPI-5337: the request's upstream trail rides on this line and no
        // other, and is cleared here whether or not the line is then
        // delivered. No calls → no `upstream` key: the line is byte-identical
        // to one from an SDK without the trail.
        $trail = $this->responseTrail();
        if ($trail !== null) {
            $data['upstream'] = $trail['calls'];
            if ($trail['dropped'] > 0) {
                $data['_upstreamTruncated'] = true;
                $data['_upstreamDropped'] = $trail['dropped'];
            }
        }

        $this->info($apiKey, 'Outgoing response', $data);
    }

    /**
     * Delivers what is buffered at call time, within `drainDeadlineMs`.
     *
     * Never throws — failures go to `onError`. Call it wherever entries must
     * have landed before the process moves on: the end of a request handler, a
     * shutdown path, before a long sleep in a worker.
     *
     * The budget is the point. This drain runs synchronously, and at shutdown
     * it runs inside the FPM request that already answered the client; without
     * a bound, an unreachable ingest costs
     * `(1 + maxRetries) × requestTimeoutMs + backoff` *per group*, which sails
     * past a typical `request_terminate_timeout` and gets the worker killed
     * mid-drain. Anything still undelivered when the budget runs out is
     * reported as `drain-timeout` and dropped.
     */
    public function flush(): void
    {
        // PAPI-5337: dropped upstream calls the rate limit held back are
        // reported now, so a burst is never left unreported at the end of a
        // request. (The `batchSize` drain does not come through here.)
        try {
            $this->reportUnreportedUpstreamLost();
        } catch (\Throwable) {
            // A caller-supplied `clock` that throws; flush() never throws.
        }
        $this->drain(self::DRAIN_FULL);
    }

    /**
     * Takes the buffer and posts it under the given drain profile.
     *
     * @param self::DRAIN_AUTO|self::DRAIN_FULL $profile
     */
    private function drain(string $profile): void
    {
        // `onError` runs inside the drain; a hook that logs through this same
        // logger would otherwise re-enter and post the batch it is reporting on.
        if ($this->draining || $this->buffer === []) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];
        $this->draining = true;

        // Both profiles get a wall-clock budget, because both can hold a
        // caller. A per-attempt timeout is not a bound on the drain: one drain
        // posts one chunk per (apiKey, labels, upstream) group, so bounding
        // only the attempt leaves the log call costing groups × the timeout —
        // 4 s for a request that logged at four levels. `0` disables.
        $budget = $profile === self::DRAIN_AUTO ? $this->autoDrainTimeoutMs : $this->drainDeadlineMs;
        $deadline = $budget > 0 ? $this->clockMs() + $budget : null;

        try {
            $pending = [];
            foreach ($this->group($batch) as $group) {
                foreach ($this->chunk($group) as $chunk) {
                    $pending[] = $chunk;
                }
            }

            $requeue = [];
            $delivered = true;

            while ($pending !== []) {
                $chunk = array_shift($pending);

                if ($deadline !== null && $this->clockMs() >= $deadline) {
                    array_unshift($pending, $chunk);

                    if ($profile === self::DRAIN_AUTO) {
                        // Never attempted, and the caller's budget is spent.
                        // Back on the buffer for the end-of-request drain —
                        // dropping here would lose entries to a budget that
                        // exists only to protect the request, not to ration
                        // delivery.
                        foreach ($pending as $unsent) {
                            foreach ($unsent as $queued) {
                                $requeue[] = $queued;
                            }
                        }
                    } else {
                        $this->abandon($pending);
                    }

                    $delivered = false;
                    break;
                }

                $outcome = $this->postBatch($chunk, $profile, $deadline);

                if ($outcome === self::OUTCOME_REQUEUE) {
                    // Still worth another go, but not on the caller's clock.
                    foreach ($chunk as $queued) {
                        $requeue[] = $queued;
                    }
                    $delivered = false;
                } elseif ($outcome === self::OUTCOME_ABANDONED) {
                    array_unshift($pending, $chunk);
                    $this->abandon($pending);
                    $delivered = false;
                    break;
                } elseif ($outcome === self::OUTCOME_DROPPED) {
                    $delivered = false;
                }
            }

            if ($requeue !== []) {
                $this->rebuffer($requeue);
            }

            if ($profile === self::DRAIN_AUTO && !$delivered) {
                // Latch: the next log call past `batchSize` must not pay
                // another timeout to rediscover the same outage.
                $this->autoDrainSuppressed = true;
            } elseif ($profile === self::DRAIN_FULL && $delivered) {
                $this->autoDrainSuppressed = false;
            }
        } catch (\Throwable $e) {
            // Belt and braces. `postBatch()` already swallows every delivery
            // failure, so reaching here means something outside that contract
            // threw — a caller-supplied `sleeper` or `clock`, most plausibly.
            // This runs from a shutdown function and from the log path, and an
            // exception escaping either is precisely the failure this SDK
            // exists to keep away from the partner's request.
            $this->report(new LoggerErrorEvent(
                LoggerErrorEvent::REASON_FLUSH_FAILED,
                'Failed to send log: flush abandoned: ' . $e->getMessage(),
                0,
                $this->droppedTotal,
                null,
                null,
                false,
                $e,
            ));
        } finally {
            $this->draining = false;
        }
    }

    /**
     * Reports and discards chunks the drain budget left undelivered.
     *
     * @param list<list<array<string, mixed>>> $chunks
     */
    private function abandon(array $chunks): void
    {
        $count = 0;
        foreach ($chunks as $chunk) {
            $count += count($chunk);
        }

        if ($count === 0) {
            return;
        }

        $this->droppedTotal += $count;
        $this->report(new LoggerErrorEvent(
            LoggerErrorEvent::REASON_DRAIN_TIMEOUT,
            sprintf(
                'Failed to send log: drain deadline exceeded (%d ms) — dropped %d undelivered %s',
                $this->drainDeadlineMs,
                $count,
                $count === 1 ? 'entry' : 'entries',
            ),
            $count,
            $this->droppedTotal,
            null,
            null,
            true,
        ));
    }

    /**
     * Returns entries an auto-drain could not deliver to the front of the
     * buffer — they are older than anything logged since — for the
     * end-of-request drain to retry properly.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function rebuffer(array $entries): void
    {
        $this->buffer = array_merge($entries, $this->buffer);
        $this->trimBuffer();
    }

    /** Monotonic milliseconds. */
    private function clockMs(): float
    {
        return ($this->clock)();
    }

    /**
     * Flushes. Present for parity with the TypeScript SDK's `shutdown()`, which
     * also stops a periodic timer this implementation does not have.
     *
     * The logger stays usable afterwards — a later log call buffers as normal
     * and is drained by the end-of-request hook — so this is safe to call on a
     * shutdown path that races with in-flight work, and safe to call twice.
     */
    public function shutdown(): void
    {
        $this->flush();
    }

    /** Alias for {@see Logger::shutdown()}. */
    public function close(): void
    {
        $this->shutdown();
    }

    /**
     * Buffer counters — how much is waiting, delivered and lost.
     *
     * `upstreamDropped` counts upstream calls (PAPI-5337) dropped because no
     * response line was left to ship them on — every one, including those a
     * rate-limited `onError` report has not mentioned yet.
     *
     * @return array{buffered: int, delivered: int, dropped: int, upstreamDropped: int}
     */
    public function stats(): array
    {
        return [
            'buffered' => count($this->buffer),
            'delivered' => $this->deliveredTotal,
            'dropped' => $this->droppedTotal,
            'upstreamDropped' => $this->upstreamLostTotal,
        ];
    }

    /**
     * @param array{slug: string, timestamp: string, value: int|float, period: string, series?: string, metadata?: array<string, mixed>} $data
     */
    public function metric(string $apiKey, array $data): void
    {
        $point = [
            'timestamp' => $data['timestamp'],
            'value' => $data['value'],
            'period' => $data['period'],
        ];

        if (isset($data['series'])) {
            $point['series'] = $data['series'];
        }

        if (isset($data['metadata'])) {
            $point['metadata'] = $data['metadata'];
        }

        $this->metrics($apiKey, $data['slug'], [$point]);
    }

    /**
     * Sends metric data points. Unlike the log methods this is **not**
     * buffered and still raises on failure — see the class docblock.
     *
     * @param array<array{timestamp: string, value: int|float, period: string, series?: string, metadata?: array<string, mixed>}> $points
     */
    public function metrics(string $apiKey, string $slug, array $points): void
    {
        if (empty($apiKey)) {
            throw new LoggerException('API key is required for metrics');
        }

        if (!is_string($slug) || empty($slug)) {
            throw new LoggerException('slug must be a non-empty string');
        }

        $normalizedSlug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9-]+$/', $normalizedSlug)) {
            throw new LoggerException('slug must contain only lowercase letters, numbers, and hyphens');
        }

        if (empty($points)) {
            throw new LoggerException('points must be a non-empty array');
        }

        if (count($points) > 1000) {
            throw new LoggerException('Maximum 1000 points per request');
        }

        // Validate series on each point
        foreach ($points as $i => $point) {
            if (isset($point['series'])) {
                if (!is_string($point['series']) || empty(trim($point['series']))) {
                    throw new LoggerException('series must be a non-empty string when provided');
                }
                if (strlen(trim($point['series'])) > 50) {
                    throw new LoggerException('series must be at most 50 characters');
                }
            }
        }

        try {
            $this->post('/metrics', $apiKey, [
                'slug' => $slug,
                'points' => $points,
            ], $this->requestTimeoutMs);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            error_log("Failed to send metrics: {$message}");
            throw new LoggerException("Failed to send metrics: {$message}");
        }
    }

    /**
     * Builds one log entry and either buffers it or (direct mode) posts it.
     *
     * In buffered mode this never throws: a missing API key, unserialisable
     * data, data that raises while being serialised, a full buffer and a dead
     * ingest all surface through `onError`.
     *
     * @param array<string, mixed> $data
     */
    private function record(string $apiKey, string $level, string $message, array $data = []): void
    {
        if (empty($apiKey)) {
            // Wording is part of the cross-language contract (logger-spec).
            $this->reject('API key is required for logging');
            return;
        }

        $queued = $this->buildEntry($apiKey, $level, $message, $data);
        if ($queued === null) {
            return;
        }

        if ($this->mode === self::MODE_DIRECT) {
            $this->postBatch([$queued], self::DRAIN_DIRECT);
            return;
        }

        $this->armShutdownFlush();
        $this->buffer[] = $queued;
        $this->trimBuffer();

        if (
            $this->batchSize > 0
            && !$this->autoDrainSuppressed
            && count($this->buffer) >= $this->batchSize
        ) {
            // Best-effort, and the only drain the caller ever waits on. One
            // attempt, `autoDrainTimeoutMs`, no backoff sleep — see drain().
            $this->drain(self::DRAIN_AUTO);
        }
    }

    /** Enforces `maxBufferSize`, dropping oldest-first and reporting. */
    private function trimBuffer(): void
    {
        $overflow = count($this->buffer) - $this->maxBufferSize;
        if ($overflow <= 0) {
            return;
        }

        // Drop the oldest: under sustained backpressure the newest entries
        // are the ones describing what is going wrong right now.
        array_splice($this->buffer, 0, $overflow);
        $this->droppedTotal += $overflow;
        $this->report(new LoggerErrorEvent(
            LoggerErrorEvent::REASON_BUFFER_OVERFLOW,
            sprintf(
                'Log buffer full (%d) — dropped %d oldest %s',
                $this->maxBufferSize,
                $overflow,
                $overflow === 1 ? 'entry' : 'entries',
            ),
            $overflow,
            $this->droppedTotal,
        ));
    }

    /**
     * Builds one queued entry, or reports the reason it could not be built and
     * returns null.
     *
     * Everything here runs on the caller's request path over the caller's own
     * values: a `JsonSerializable` in `$data` whose `jsonSerialize()` raises,
     * a supplied `timestampProvider` that raises, a `bcmul()` that is not
     * there because ext-bcmath is not installed. `json_encode` returning
     * `false` was already handled; an *exception* thrown out of any of it was
     * not, and would have escaped a log method whose entire contract is that
     * it never throws. So construction is guarded as a unit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildEntry(string $apiKey, string $level, string $message, array $data): ?array
    {
        try {
            return $this->composeEntry($apiKey, $level, $message, $data);
        } catch (LoggerException $e) {
            // Direct mode's own raise, from `reject()` below. Already the
            // right exception with the right message — do not re-wrap it.
            throw $e;
        } catch (\Throwable $e) {
            $this->reject('Failed to send log: log entry could not be built: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function composeEntry(string $apiKey, string $level, string $message, array $data): ?array
    {
        $timestampMs = ($this->timestampProvider)();
        $timestampNs = bcmul((string) $timestampMs, '1000000');

        // Resolved once, so every field on this entry comes from the same
        // scope (PAPI-5336) — the request's own inside a scope, else the
        // logger-wide context.
        $context = $this->activeScope()->context;

        $contextDefaults = array_filter([
            'request_id' => $context['requestId'] ?? null,
            'path' => $context['path'] ?? null,
            'method' => $context['method'] ?? null,
            'status_code' => $context['statusCode'] ?? null,
            'duration_ms' => $context['duration'] ?? null,
            'correlation_id' => $context['correlationId'] ?? null,
        ], fn ($v) => $v !== null);

        $lineBase = ['level' => $level, 'message' => $message];
        if (($context['partnerId'] ?? null) !== null) {
            $lineBase['partnerId'] = $context['partnerId'];
        }

        // Merge context defaults (nulls already filtered) then user data (preserve nulls)
        $lineData = array_merge($lineBase, $contextDefaults, $data);

        // `$data` is whatever the caller passed. Invalid UTF-8 or a recursive
        // structure makes `json_encode` return false — which, before 2.0.0,
        // shipped a literal `false` as the log line.
        $line = json_encode($lineData, self::LINE_JSON_FLAGS);
        if ($line === false) {
            $this->reject('Failed to send log: log entry could not be serialised: ' . json_last_error_msg());
            return null;
        }

        // Labels and upstream attribution are snapshotted here, not at drain
        // time — a later `setContext` must not retro-label queued entries.
        $labels = ['level' => $level];
        if (isset($context['partnerId'])) {
            $labels['partnerId'] = $context['partnerId'];
        }
        // PAPI-687: direction rides in labels so ingest can promote it to a
        // Loki stream label. Omitted when unset — ingest defaults to 'inbound'.
        if (isset($context['direction'])) {
            $labels['direction'] = $context['direction'];
        }

        // PAPI-687: upstream attribution is request-level, not a stream label
        // (base URLs are high-cardinality). Ingest folds these into the line.
        $root = [];
        if (isset($context['upstreamIntegration'])) {
            $root['upstream_integration'] = $context['upstreamIntegration'];
        }
        if (isset($context['upstreamBaseUrl'])) {
            $root['upstream_base_url'] = $context['upstreamBaseUrl'];
        }

        return [
            'apiKey' => $apiKey,
            'labels' => $labels,
            'root' => $root,
            'entry' => ['timestamp' => $timestampNs, 'line' => $line],
        ];
    }

    /**
     * Reports an entry that never made it into the buffer — or, in direct
     * mode, raises the way the pre-2.0 SDK did.
     */
    private function reject(string $message): void
    {
        if ($this->mode === self::MODE_DIRECT) {
            throw new LoggerException($message);
        }

        $this->droppedTotal++;
        $this->report(new LoggerErrorEvent(
            LoggerErrorEvent::REASON_INVALID_ENTRY,
            $message,
            1,
            $this->droppedTotal,
        ));
    }

    /**
     * Hands an error to the caller's hook without letting it escape.
     *
     * Logger-internal events are logger-wide (PAPI-5336, rule 4): the hook
     * runs at the root scope, so a hook that logs on this logger writes a
     * logger-wide line, never one stamped with the request that happened to
     * trigger the report.
     */
    private function report(LoggerErrorEvent $event): void
    {
        try {
            $this->atRootScope(fn () => ($this->onError)($event));
        } catch (\Throwable) {
            // A broken hook is not worth breaking the caller's request over.
        }
    }

    /**
     * Arms the end-of-request drain, once, on the first buffered entry.
     *
     * Lazy rather than constructor-time so a logger that is never used does
     * not pin itself in memory for the life of the request. The closure holds
     * `$this`, which is what keeps the buffer alive until the hook runs.
     */
    private function armShutdownFlush(): void
    {
        if ($this->shutdownArmed || !$this->flushOnShutdown) {
            return;
        }
        $this->shutdownArmed = true;

        register_shutdown_function($this->drainOnShutdown(...));
    }

    /** The end-of-request drain. Registered by {@see self::armShutdownFlush()}. */
    private function drainOnShutdown(): void
    {
        // Hand the response to the client BEFORE talking to ingest, so
        // delivery costs the partner's request nothing. Returns false if
        // the framework already called it (Symfony/Laravel do) — harmless.
        if ($this->finishRequestOnShutdown && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Belongs to no request (PAPI-5336, rule 4) — and an `exit` inside
        // runWithContext() skips the `finally` that would have closed its
        // scope, so the stack may still name one here.
        $this->atRootScope(fn () => $this->flush());

        // PHP runs shutdown functions in registration order, and this one is
        // armed on the FIRST log call — so anything the application registered
        // later runs after this drain. An entry logged from there (a
        // fatal-error handler, a debug bar) would otherwise sit in the buffer
        // until the process died, delivered by nothing and reported to no one.
        // Disarming lets that entry arm a fresh hook: a function registered
        // *during* shutdown still runs.
        $this->shutdownArmed = false;
    }

    /**
     * Groups a drained batch into one request per distinct
     * (apiKey, labels, upstream attribution) shape.
     *
     * Not an optimisation detail — `labels.level`, `labels.partnerId` and the
     * upstream fields are per-request in the ingest contract, so entries that
     * disagree on them cannot share a POST without being mislabelled.
     *
     * @param list<array<string, mixed>> $batch
     * @return list<list<array<string, mixed>>>
     */
    private function group(array $batch): array
    {
        $groups = [];
        foreach ($batch as $queued) {
            $key = $queued['apiKey']
                . "\0" . json_encode($queued['labels'])
                . "\0" . json_encode($queued['root']);
            $groups[$key][] = $queued;
        }

        return array_values($groups);
    }

    /**
     * Splits one group into requests ingest will accept: at most 1000 entries,
     * and at most 1 MB of log line per request.
     *
     * The byte budget matters only because of batching. One entry per POST
     * could never approach ingest's 5 MB body limit; a thousand of them can,
     * and a 413 is not retryable — it would be silent loss.
     *
     * @param list<array<string, mixed>> $group
     * @return list<list<array<string, mixed>>>
     */
    private function chunk(array $group): array
    {
        $chunks = [];
        $chunk = [];
        $bytes = 0;

        foreach ($group as $queued) {
            $size = strlen($queued['entry']['line']);
            // An entry too big for a whole request still goes on its own
            // rather than being dropped here — let ingest refuse it.
            if ($chunk !== [] && (
                count($chunk) >= self::MAX_ENTRIES_PER_REQUEST
                || $bytes + $size > self::MAX_REQUEST_LINE_BYTES
            )) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[] = $queued;
            $bytes += $size;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Posts one batch under the given drain profile.
     *
     * Only `DRAIN_FULL` retries, and only within `$deadline`. `DRAIN_AUTO`
     * takes exactly one short attempt and hands a still-retryable chunk back
     * for later; `DRAIN_DIRECT` takes one attempt and raises.
     *
     * @param list<array<string, mixed>> $chunk
     * @param self::DRAIN_* $profile
     * @param float|null $deadline Monotonic ms after which a FULL drain stops.
     * @return self::OUTCOME_*
     */
    private function postBatch(array $chunk, string $profile, ?float $deadline = null): string
    {
        $first = $chunk[0];
        $body = ['labels' => $first['labels']];
        foreach ($first['root'] as $key => $value) {
            $body[$key] = $value;
        }
        $body['entries'] = array_map(static fn (array $queued) => $queued['entry'], $chunk);

        $count = count($chunk);

        for ($attempt = 0; ; $attempt++) {
            try {
                $this->post('/logs', $first['apiKey'], $body, $this->timeoutFor($profile, $deadline));
                $this->deliveredTotal += $count;
                return self::OUTCOME_DELIVERED;
            } catch (\Throwable $e) {
                $status = $this->statusOf($e);
                // 429 and 5xx say "come back later" — ingest collapses Loki
                // 429s into a 500 today, so both have to be retryable. Any
                // other 4xx means the request itself is wrong and will be
                // wrong again next time.
                $retryable = $status === null
                    || $status === 408
                    || $status === 429
                    || $status >= 500;

                if ($profile === self::DRAIN_FULL && $retryable && $attempt < $this->maxRetries) {
                    $delay = $this->backoffDelayMs($attempt);

                    if ($deadline !== null) {
                        $remaining = $deadline - $this->clockMs();
                        // No budget for the sleep, let alone the attempt after
                        // it: stop here and let the drain report the rest.
                        if ($remaining <= $delay) {
                            return self::OUTCOME_ABANDONED;
                        }
                    }

                    ($this->sleeper)($delay);
                    continue;
                }

                if ($profile === self::DRAIN_AUTO && $retryable) {
                    // Worth another go, but not on the caller's clock. Back on
                    // the buffer for the end-of-request drain, which runs after
                    // the response has already been sent. Deliberately NOT
                    // counted as dropped — nothing has been lost yet.
                    return self::OUTCOME_REQUEUE;
                }

                // v1 wording, kept so anything matching on it still matches.
                $message = 'Failed to send log: ' . $e->getMessage();

                if ($profile === self::DRAIN_DIRECT) {
                    error_log($message);
                    throw new LoggerException($message);
                }

                $this->droppedTotal += $count;
                $this->report(new LoggerErrorEvent(
                    LoggerErrorEvent::REASON_FLUSH_FAILED,
                    $message,
                    $count,
                    $this->droppedTotal,
                    $status,
                    $attempt + 1,
                    $retryable,
                    $e,
                ));
                return self::OUTCOME_DROPPED;
            }
        }
    }

    /**
     * The per-request deadline for one attempt under this profile.
     *
     * A FULL drain's own `requestTimeoutMs` is clamped to what is left of the
     * drain budget — otherwise a blackholed ingest would spend a full
     * `requestTimeoutMs` past the budget on every group, which is exactly the
     * overrun the budget exists to prevent.
     */
    private function timeoutFor(string $profile, ?float $deadline): int
    {
        // An AUTO drain's own budget is the ceiling for the whole drain, so a
        // single attempt can never be allowed more than what is left of it.
        $ceiling = $profile === self::DRAIN_AUTO
            ? $this->autoDrainTimeoutMs
            : $this->requestTimeoutMs;

        if ($deadline === null) {
            return $ceiling;
        }

        $remaining = (int) max(0, $deadline - $this->clockMs());

        // `requestTimeoutMs: 0` disables the per-request deadline, but the
        // drain budget still bounds the attempt.
        if ($ceiling <= 0) {
            return max(1, $remaining);
        }

        return max(1, min($ceiling, $remaining));
    }

    /**
     * Equal-jitter exponential backoff: half the window is the deterministic
     * step, half is random, so a fleet that all lost ingest at once does not
     * come back in lockstep and knock it over again.
     */
    private function backoffDelayMs(int $attempt): int
    {
        $ceiling = min($this->retryMaxDelayMs, $this->retryBaseDelayMs * (2 ** $attempt));

        return (int) round($ceiling / 2 + ($this->randomizer)() * ($ceiling / 2));
    }

    /** The HTTP status a failure carried, or null for a transport fault. */
    private function statusOf(\Throwable $e): ?int
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return $e->getResponse()->getStatusCode();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     * @param int $timeoutMs Per-request deadline; `0` leaves Guzzle unbounded.
     */
    private function post(string $path, string $apiKey, array $body, int $timeoutMs): void
    {
        $options = [
            'json' => $body,
            // Never the caller's upstream call, even through a client that
            // carries an upstreamMiddleware().
            self::INTERNAL_REQUEST_OPTION => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-tenant-token' => $this->tenantToken,
            ],
        ];

        if ($timeoutMs > 0) {
            // Guzzle defaults to no timeout at all: a blackholed ingest would
            // otherwise hang the flush, and in direct mode the caller with it.
            $options['timeout'] = $timeoutMs / 1000;
            $options['connect_timeout'] = $timeoutMs / 1000;
        }

        $this->httpClient->request('POST', "{$this->baseUrl}{$path}", $options);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $key => $value) {
            $redacted[$key] = in_array(strtolower($key), self::SENSITIVE_HEADERS, true)
                ? '[REDACTED]'
                : $value;
        }
        return $redacted;
    }
}

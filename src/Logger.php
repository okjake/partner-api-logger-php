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
 * drain. There are four drains:
 *
 * 1. `flush()` — deliver what is buffered at call time. Always available.
 * 2. `batchSize` — reaching it drains automatically (default 100 entries).
 * 3. A process-wide `register_shutdown_function` hook, registered once, on
 *    the first buffered entry. It calls `fastcgi_finish_request()` first where
 *    the SAPI provides it, so the client already has its response before a
 *    single byte goes to ingest, then drains every logger still holding
 *    entries. Under PHP-FPM and the other per-request SAPIs it holds those
 *    loggers until it runs, exactly as 2.1.0 did. On a long-lived worker
 *    (`cli`: Octane, RoadRunner, Swoole, `queue:work`) it holds them weakly
 *    (FLT-1522), so a dropped logger is collected as usual — which is what
 *    (4) is for.
 * 4. Destruction, on a long-lived worker only (since 2.1.1). A logger dropped
 *    with entries still buffered delivers them as it is destroyed: whenever
 *    its last reference goes, on a function return or whenever the cycle
 *    collector happens to run. That can be mid-request, so it costs what (2)
 *    costs — see below — and drops, through `onError`, what it could not
 *    deliver.
 *
 * A shutdown function fires once per PROCESS, so on a long-lived worker
 * (3) is not an end-of-request drain. Under Laravel the service provider
 * registers a `terminating` callback that drains the logger the request used
 * after every HTTP request — under Octane that is the request sandbox's
 * logger (FLT-1522). Nothing drains per queued job: call `flush()` at the end
 * of each job. A child forked with `pcntl_fork()` inherits the pending hook
 * and the parent's buffer, so it delivers the parent's buffered entries again
 * at its own exit (2.1.0 did too): `flush()` before forking.
 *
 * **What a caller can be made to wait for.** Only (2) runs inside a log call,
 * and only (4) can run anywhere else in a request. Both are deliberately
 * cheap: one attempt per batch, the drain *as a whole* bounded by
 * `autoDrainTimeoutMs` (default 1000 ms), no backoff sleep. Any chunk (2)
 * could not deliver — refused for a retryable reason, or never reached before
 * the budget ran out — goes back on the buffer rather than being retried on
 * the request path, and a failed (2) then latches off for the rest of the
 * request, so N log calls cannot cost N timeouts: a single log call blocks
 * for at most `autoDrainTimeoutMs`, and a whole request for at most that
 * once. The budget has to cover the whole drain and not just one attempt: a
 * drain posts one request per (apiKey, labels, upstream) group, so a
 * per-attempt bound would still let a request that logged at four levels pay
 * four timeouts inside a single `info()`.
 *
 * (4) has no later drain to hand anything to: it delivers synchronously, one
 * attempt per batch within one `autoDrainTimeoutMs`, and reports what it
 * could not deliver as `flush-failed` (attempted) or `drain-timeout` (never
 * reached) and drops it. Each logger dropped with a buffer can cost its
 * request that much once; keep one logger for the request rather than one
 * per call.
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
 * **Stdout mode (PAPI-5498).** `['mode' => Logger::MODE_STDOUT]` selects the
 * spec's **pipeline** profile: every log call writes one JSON line to a sink
 * (the process's stdout by default) for the customer's own log pipeline to
 * deliver, and performs no network I/O, no buffering and no retries. The
 * line names its partner by {@see PartnerReference}, never by the app key.
 * Context, request scopes and the upstream trail are unchanged; see
 * {@see self::composeStdoutLine()}.
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
     * Log calls write one spec-shaped JSON line to the `stdoutSink` and
     * return; nothing is sent over the network (the spec's pipeline profile,
     * PAPI-5498). `metric()` / `metrics()` still POST.
     */
    public const MODE_STDOUT = 'stdout';

    /**
     * The `partnerapi_line` marker on every stdout line: the spec version that
     * defined the line shape. It changes only when the line shape does.
     */
    public const STDOUT_LINE_VERSION = '1.6.0';

    /**
     * `json_encode` flags for a stdout line. After the byte-exact prefix the
     * line is compared by value, so escaping need not match any other SDK.
     * `JSON_INVALID_UTF8_SUBSTITUTE` writes U+FFFD for invalid UTF-8 instead
     * of failing the call (spec § Stdout line, "Encoding"). U+2028/U+2029
     * stay escaped (no `JSON_UNESCAPED_LINE_TERMINATORS`), and a raw LF can
     * never appear: `json_encode` always escapes control characters.
     */
    private const STDOUT_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Default `stdoutSink`: the process's standard output. */
    private const DEFAULT_STDOUT_SINK = 'php://stdout';

    /**
     * Partner references cached per app key. A logger normally sees a handful
     * of keys; the cache is cleared when it reaches this size, so a caller
     * passing unbounded distinct keys cannot grow it without limit.
     */
    private const MAX_CACHED_REFERENCES = 256;

    /**
     * Context field → stdout line field, in the push line's order (spec
     * § Logging). `partnerId` is handled beside `level` / `message`.
     */
    private const STDOUT_CONTEXT_FIELDS = [
        'requestId' => 'request_id',
        'path' => 'path',
        'method' => 'method',
        'statusCode' => 'status_code',
        'duration' => 'duration_ms',
        'correlationId' => 'correlation_id',
    ];

    /** Context field → stdout envelope key; omitted when unset or empty. */
    private const STDOUT_ENVELOPE_FIELDS = [
        'direction' => 'partnerapi_direction',
        'upstreamIntegration' => 'partnerapi_upstream_integration',
        'upstreamBaseUrl' => 'partnerapi_upstream_base_url',
    ];

    /** Data keys under this prefix are reserved for the envelope and dropped. */
    private const STDOUT_RESERVED_PREFIX = 'partnerapi_';

    /**
     * How often a file-path sink checks whether its file was rotated away
     * (renamed or deleted, then recreated). Once a second keeps the check — a
     * `stat()` — off the per-line path under load, and a rotation is noticed
     * within a second; lines written in that second land in the rotated file,
     * which still exists and which a collector reading by fingerprint still
     * reads.
     */
    private const SINK_ROTATION_CHECK_MS = 1000;

    /**
     * At most one `write-failed` report per this window, carrying every line
     * lost since the last one; every loss is still counted in `stats()`. A
     * closed stdout otherwise costs one report — one `error_log()` with the
     * default hook — per log call.
     */
    private const WRITE_FAILED_REPORT_INTERVAL_MS = 60000;

    /** The process's own standard streams, as a stdout sink names them. */
    private const PROCESS_STDIO_SINKS = ['php://stdout', 'php://stderr', 'php://fd/1', 'php://fd/2'];

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
     * - `FINAL`   — a logger destroyed with entries buffered (FLT-1522). It
     *   can run mid-request, before the response, so it costs what `AUTO`
     *   does: one attempt per chunk, no backoff sleep, bounded as a whole by
     *   `autoDrainTimeoutMs`. There is no later drain to hand anything to, so
     *   what it could not deliver is reported and dropped, not rebuffered.
     */
    private const DRAIN_DIRECT = 'direct';
    private const DRAIN_AUTO = 'auto';
    private const DRAIN_FULL = 'full';
    private const DRAIN_FINAL = 'final';

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
        'stdoutSink',
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

    /** @var callable(): (int|float|string) Epoch ms; see nanosecondTimestamp(). */
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

    /**
     * Stdout mode's sink, normalised from `stdoutSink`: exactly one of a
     * callable, an open stream, or a URI/path opened on first write.
     */
    private ?\Closure $sinkCallable = null;

    /** @var resource|null */
    private $sinkStream = null;

    private ?string $sinkUri = null;

    /** Whether `$sinkUri` names a file (a plain path or `file://`), which may be rotated. */
    private bool $sinkIsPath = false;

    /** @var array{0: int, 1: int}|null (dev, inode) of the file the open path sink writes to. */
    private ?array $sinkIdentity = null;

    /** When the path sink last checked for rotation (this logger's clock, ms). */
    private ?float $sinkCheckedAt = null;

    /** @var array<string, string> App key → partner reference; see partnerReference(). */
    private array $partnerReferences = [];

    /**
     * Re-entrancy guards for stdout mode, the counterpart of `$draining`: a
     * callable sink that logs through this logger, or an `onError` hook that
     * logs while the sink is failing, would otherwise recurse without bound.
     *
     * Keyed by execution context ({@see self::executionContext()}), not one
     * flag for the logger: a sink write can suspend (a Fiber, a Swoole
     * coroutine hook), and another request logging meanwhile is not
     * re-entering anything.
     *
     * @var array<string, true>
     */
    private array $emittingIn = [];

    /** @var array<string, true> */
    private array $reportingWriteFailureIn = [];

    /** Lines lost to the sink, in total and since the last report. */
    private int $writeLostTotal = 0;
    private int $writeLostUnreported = 0;
    private ?float $writeLostReportedAt = null;
    private string $lastWriteFailure = '';
    private ?\Throwable $lastWriteFailureCause = null;

    /** @var (\Closure(): int)|false|null Swoole's coroutine-id reader; false when absent. */
    private static \Closure|false|null $coroutineId = null;

    /** @var list<array{apiKey: string, labels: array<string, string>, root: array<string, string>, entry: array{timestamp: string, line: string}}> */
    private array $buffer = [];

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
     * Loggers holding entries the process-wide shutdown drain must deliver
     * (FLT-1522). The keys are weak, so this map alone never keeps a logger
     * alive: a per-request logger on a long-lived worker (an un-warmed
     * Octane sandbox's) is collected like any other object and drops out.
     * Until 2.1.1 each logger registered its own shutdown function, a closure
     * over `$this`, which pinned every per-request logger — and its buffer,
     * Guzzle client and options — until the worker exited.
     *
     * Null until first use: a `WeakMap` cannot be a property initialiser.
     *
     * @var \WeakMap<Logger, true>|null
     */
    private static ?\WeakMap $awaitingShutdown = null;

    /**
     * The same loggers, held STRONGLY, on a per-request SAPI only (see
     * {@see self::PER_REQUEST_SAPIS}). There every static is reset when the
     * request ends, so nothing can leak, and holding the logger to the hook
     * keeps 2.1.0's behaviour exactly: delivery after
     * `fastcgi_finish_request()`, never a destroy-time drain inside the
     * request. Keyed by `spl_object_id()`.
     *
     * @var array<int, Logger>
     */
    private static array $heldUntilShutdown = [];

    /**
     * SAPIs that run one request per PHP request lifecycle (statics and
     * shutdown functions reset when it ends): PHP-FPM, FastCGI, CGI, mod_php,
     * LiteSpeed and the built-in server (`artisan serve`). Anything else —
     * `cli` (Octane on Swoole or RoadRunner, `queue:work`, scripts),
     * `phpdbg`, `frankenphp` (whose worker mode keeps the process across
     * requests) — is treated as long-lived.
     */
    private const PER_REQUEST_SAPIS = ['fpm-fcgi', 'cgi-fcgi', 'cgi', 'apache2handler', 'litespeed', 'cli-server'];

    /** Whether the one shutdown hook is registered and has not run yet. */
    private static bool $shutdownHookPending = false;

    /** Whether that hook is running now; see drainAllOnShutdown(). */
    private static bool $shutdownHookRunning = false;

    /** PHP_SAPI unless a test says otherwise; see overrideShutdownHook(). */
    private static ?string $sapi = null;

    /** Set once the hook has finished the response, so it never does twice. */
    private static bool $responseFinished = false;

    /** @var (\Closure(callable): void)|null See overrideShutdownHook(). */
    private static ?\Closure $registerShutdownHook = null;

    /** @var (\Closure(): void)|null See overrideShutdownHook(). */
    private static ?\Closure $finishResponse = null;

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
     *     clock?: callable(): float,
     *     stdoutSink?: string|resource|callable(string): void
     * } $options
     *
     * - `mode` — `Logger::MODE_BUFFERED` (default), `Logger::MODE_DIRECT` or
     *   `Logger::MODE_STDOUT`.
     * - `stdoutSink` — where `MODE_STDOUT` writes each line (ignored in the
     *   other modes). Default `'php://stdout'`. One of:
     *   - a **string**: a stream URI or file path (`'php://stderr'`,
     *     `'/var/log/app/partner-api.log'`), opened in append mode on the
     *     first line and kept open. A string is always a path, never a
     *     function name.
     *   - an open, writable **stream resource**. The logger never closes it.
     *   - a **callable** receiving each complete line, trailing `"\n"`
     *     included, once per line. Its return value is ignored.
     *   Each line is one `fwrite()` (or one call). A sink that throws, cannot
     *   be opened, or takes fewer bytes than the line is reported to
     *   `onError` as `write-failed`; the log call never throws.
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
     *   drain, the only drain a log call waits on (default 1000; `0`
     *   disables the bound). This is the SDK's whole budget for one log call,
     *   across every group and chunk: one attempt each, no backoff sleep, and
     *   anything it could not deliver in time goes back on the buffer for the
     *   end-of-request drain rather than being retried on the request path.
     *   Also the budget for the drain a logger runs as it is destroyed with
     *   entries still buffered on a long-lived worker, which can run
     *   mid-request too (FLT-1522); that one drops what it could not deliver,
     *   as nothing drains after it. With `0` that drain bounds each attempt
     *   by `requestTimeoutMs` instead.
     * - `drainDeadlineMs` — total wall-clock budget for one `flush()` /
     *   `shutdown()` / end-of-request drain, across every group, chunk, retry
     *   and backoff (default 5000; `0` disables the bound). Whatever is still
     *   undelivered when it runs out is reported as `drain-timeout` and
     *   dropped. Bounds the drain that runs inside the FPM request after
     *   `fastcgi_finish_request()` — keep it well under the pool's
     *   `request_terminate_timeout`, or FPM kills the worker mid-drain.
     * - `flushOnShutdown` — drain from the process-wide
     *   `register_shutdown_function` hook, and, on a long-lived worker, when
     *   the logger is destroyed with entries still buffered (one attempt per
     *   batch, within `autoDrainTimeoutMs`) (default true).
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
        // `static`: a closure that captured `$this` would make every logger
        // a reference cycle, freed only when the cycle collector next runs.
        $this->timestampProvider = $timestampProvider ?? static fn () => (int) (microtime(true) * 1000);

        // Same reasoning as the unknown-key check above, and the same blast
        // radius: `PARTNER_API_LOG_MODE=diret` silently coercing to buffered
        // would leave a consumer that asked for the 1.x profile quietly on the
        // new one, catching nothing where it expects to catch.
        $mode = $options['mode'] ?? self::MODE_BUFFERED;
        if ($mode !== self::MODE_BUFFERED && $mode !== self::MODE_DIRECT && $mode !== self::MODE_STDOUT) {
            throw new LoggerException(sprintf(
                'Unknown Logger mode: %s. Known modes: %s, %s, %s',
                is_scalar($mode) ? (string) $mode : get_debug_type($mode),
                self::MODE_BUFFERED,
                self::MODE_DIRECT,
                self::MODE_STDOUT,
            ));
        }
        $this->mode = $mode;

        // Every stdout line's reference is keyed on the token: an empty one
        // would write lines that match no partner, silently, at ingest.
        if ($mode === self::MODE_STDOUT && $tenantToken === '') {
            throw new LoggerException(
                'Logger mode stdout needs tenantToken: the partner reference on every line is derived from it,'
                . ' and it must be the token your collector sends to ingest'
            );
        }

        // Validated in every mode, so a bad value is caught at construction
        // when the deployment is switched to stdout, not on the first line.
        $sink = $options['stdoutSink'] ?? self::DEFAULT_STDOUT_SINK;
        if (is_string($sink)) {
            $this->sinkIsPath = self::validateSinkUri($sink);
            $this->sinkUri = $sink;
        } elseif (is_resource($sink) && get_resource_type($sink) === 'stream') {
            $this->sinkStream = $sink;
        } elseif (is_callable($sink)) {
            $this->sinkCallable = \Closure::fromCallable($sink);
        } else {
            throw new LoggerException(
                'Logger option stdoutSink must be a stream URI or file path, an open stream resource,'
                . ' or a callable receiving each line; got ' . get_debug_type($sink)
            );
        }

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

        // Last: report() needs the hook and the scope state set above.
        if (
            $mode === self::MODE_STDOUT
            && $this->sinkUri !== null
            && in_array(strtolower($this->sinkUri), self::PROCESS_STDIO_SINKS, true)
            && (self::$sapi ?? PHP_SAPI) === 'fpm-fcgi'
        ) {
            $this->report(new LoggerErrorEvent(
                LoggerErrorEvent::REASON_SINK_WARNING,
                "Logger mode stdout is writing to {$this->sinkUri} under PHP-FPM, which discards worker output"
                . ' unless the pool sets catch_workers_output = yes, and then splits every line longer than'
                . ' log_limit. Set stdoutSink to a file your collector reads.',
                0,
                0,
            ));
        }
    }

    /**
     * Checks a string `stdoutSink` and says whether it is a file path (which
     * may be rotated) rather than a process stream.
     *
     * Only sinks that end up in a log stream are accepted: the process's own
     * stdout / stderr / descriptors, and local files. `php://output` would
     * write log lines — bodies, headers — into the HTTP response under
     * PHP-FPM; `php://input` cannot be written; `php://memory` / `php://temp`
     * keep the lines in the process; and a network or archive wrapper
     * (`http://`, `ftp://`, `phar://`, `data:`) is not a log sink.
     */
    private static function validateSinkUri(string $sink): bool
    {
        if ($sink === '') {
            throw new LoggerException('Logger option stdoutSink must not be an empty string');
        }

        $allowed = 'php://stdout, php://stderr, php://fd/<n>, a file:// URI or a local file path';
        if (preg_match('~^([a-zA-Z][a-zA-Z0-9+.\-]*)://(.*)\z~s', $sink, $m) === 1) {
            $scheme = strtolower($m[1]);
            if ($scheme === 'file') {
                return true;
            }
            // PHP matches php:// targets case-insensitively.
            if ($scheme === 'php' && preg_match('~^(?:stdout|stderr|fd/[0-9]+)\z~i', $m[2]) === 1) {
                return false;
            }
            $why = $scheme === 'php' && strtolower($m[2]) === 'output'
                ? ' (under PHP-FPM it would write log lines into the HTTP response)'
                : '';
            throw new LoggerException("Logger option stdoutSink {$sink} is not a log sink{$why}: use {$allowed}");
        }
        if (preg_match('~^data:~i', $sink) === 1) {
            throw new LoggerException("Logger option stdoutSink {$sink} is not a log sink: use {$allowed}");
        }

        return true;
    }

    /**
     * Never prints the tenant token, nor the raw app keys this logger holds
     * as reference-cache keys (`var_dump()`, `print_r()`, and any dumper
     * that honours `__debugInfo()`). Safe on an object whose constructor
     * never ran.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'mode' => $this->mode ?? null,
            'baseUrl' => $this->baseUrl ?? null,
            'tenantToken' => '[REDACTED]',
            'stdoutSink' => $this->sinkUri
                ?? ($this->sinkCallable !== null ? 'callable' : ($this->sinkStream !== null ? 'stream resource' : null)),
            'cachedPartnerReferences' => count($this->partnerReferences),
            'stats' => $this->stats(),
        ];
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

            if ($this->mode === self::MODE_STDOUT) {
                $data = $this->stdoutRequestData($apiKey, $request, $correlationId);
            } else {
                $data = [
                    'method' => $request['method'],
                    'path' => $request['path'],
                    'headers' => $this->redactHeaders($headers),
                    'correlation_id' => $correlationId,
                ];

                if (array_key_exists('body', $request)) {
                    $data['body'] = $request['body'];
                }
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

            $data = $this->mode === self::MODE_STDOUT
                ? $this->stdoutResponseData($apiKey, $response)
                : [
                    'status_code' => $response['statusCode'],
                    'headers' => isset($response['headers']) ? $this->redactHeaders($response['headers']) : null,
                    'body' => $response['body'] ?? null,
                    'duration_ms' => $response['duration'],
                    'correlation_id' => $response['correlationId'],
                ];
        } catch (\Throwable $e) {
            // The exchange still answered: end it and clear its trail, as a
            // delivered response would. The calls are lost with the line,
            // which reject() reports; leaving them would ship them on the
            // NEXT response and keep this exchange open for nested scopes.
            $this->responseTrail();
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
        $this->flushFrom(null);
    }

    /**
     * {@see Logger::flush()}, with the `drainDeadlineMs` budget counted from
     * `$startedAtMs` (this logger's clock) instead of from now. The shutdown
     * hook passes the moment it started, so draining several loggers costs
     * the process one budget, not one each.
     */
    private function flushFrom(?float $startedAtMs): void
    {
        // PAPI-5337: dropped upstream calls the rate limit held back are
        // reported now, so a burst is never left unreported at the end of a
        // request. (The `batchSize` drain does not come through here.)
        try {
            $this->reportUnreportedUpstreamLost();
        } catch (\Throwable) {
            // A caller-supplied `clock` that throws; flush() never throws.
        }
        if ($this->mode === self::MODE_STDOUT) {
            // Nothing is buffered in stdout mode: each line was written by its
            // log call. Flushing the stream, and reporting write failures the
            // rate limit held back, is all there is to do.
            $this->flushSink();
            $this->reportUnreportedWriteFailures();
            return;
        }
        $this->drain(self::DRAIN_FULL, $startedAtMs);
    }

    /**
     * Takes the buffer and posts it under the given drain profile.
     *
     * @param self::DRAIN_AUTO|self::DRAIN_FULL|self::DRAIN_FINAL $profile
     * @param float|null $startedAtMs When the budget started; null is now.
     */
    private function drain(string $profile, ?float $startedAtMs = null): void
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
        $budget = $profile === self::DRAIN_FULL ? $this->drainDeadlineMs : $this->autoDrainTimeoutMs;

        // Read in the catch below, which accounts for every entry of `$batch`
        // the drain had not settled when something threw.
        $deliveredBefore = $this->deliveredTotal;
        $droppedBefore = $this->droppedTotal;
        $requeue = [];
        $rebuffered = 0;

        try {
            // Inside the try: a caller-supplied `clock` that throws must not
            // escape a log call, flush(), the shutdown hook or a destructor.
            $deadline = $budget > 0 ? ($startedAtMs ?? $this->clockMs()) + $budget : null;

            $pending = [];
            foreach ($this->group($batch) as $group) {
                foreach ($this->chunk($group) as $chunk) {
                    $pending[] = $chunk;
                }
            }

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
                        $this->abandon($pending, $budget);
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
                    $this->abandon($pending, $budget);
                    $delivered = false;
                    break;
                } elseif ($outcome === self::OUTCOME_DROPPED) {
                    $delivered = false;
                }
            }

            if ($requeue !== []) {
                $rebuffered = $this->rebufferCounted($requeue);
                $requeue = [];
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
            // This runs from a shutdown function, a destructor and the log
            // path, and an exception escaping any of them is precisely the
            // failure this SDK exists to keep away from the partner's request.
            //
            // Every entry of the batch not yet delivered, dropped (and
            // reported) or put back on the buffer is lost here; count it.
            if ($requeue !== []) {
                $rebuffered += $this->rebufferCounted($requeue);
            }
            $lost = max(0, count($batch)
                - ($this->deliveredTotal - $deliveredBefore)
                - ($this->droppedTotal - $droppedBefore)
                - $rebuffered);
            $this->droppedTotal += $lost;
            $this->report(new LoggerErrorEvent(
                LoggerErrorEvent::REASON_FLUSH_FAILED,
                'Failed to send log: flush abandoned: ' . $e->getMessage(),
                $lost,
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
     * @param int $budgetMs The budget that ran out, for the message.
     */
    private function abandon(array $chunks, int $budgetMs): void
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
                $budgetMs,
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

    /**
     * {@see self::rebuffer()}, returning how far the buffer grew: what it
     * kept, net of any overflow that trimming dropped (and already counted
     * and reported as `buffer-overflow`). drain()'s loss accounting subtracts
     * this, so an overflowed entry is never counted as both dropped and kept.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function rebufferCounted(array $entries): int
    {
        $before = count($this->buffer);
        $this->rebuffer($entries);

        return count($this->buffer) - $before;
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
     * Until then the process-wide shutdown hook no longer visits it.
     */
    public function shutdown(): void
    {
        $this->flush();
        self::$awaitingShutdown?->offsetUnset($this);
        unset(self::$heldUntilShutdown[spl_object_id($this)]);
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
     * In stdout mode `buffered` is always 0 and `delivered` counts lines
     * handed to the sink in full: what happens after the write (in the
     * collector, at ingest) never reaches the SDK.
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

        if ($this->mode === self::MODE_STDOUT) {
            // No buffer, no batching, no retries, no shutdown drain: the line
            // is written now or reported.
            $this->writeStdoutLine($apiKey, $level, $message, $data);
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
     * Epoch milliseconds → the decimal nanosecond string ingest expects, or
     * null for a value that is not a timestamp.
     *
     * Up to 2.0.0 this was bcmath's multiply of `(string) $ms` by 1000000 at
     * scale 0. That needs ext-bcmath — never declared, and absent from the
     * official php images — so there every entry failed (FLT-1306). This
     * reproduces the same bytes without it: a multiply by 10^6 is a six-place
     * decimal shift, truncated toward zero as scale 0 truncates.
     *
     * - int: append six zeros. Exact even at PHP_INT_MAX, where a multiply
     *   would overflow to a float.
     * - float: the same `(string)` cast bcmath was handed, so it keeps what
     *   that cast keeps under the `precision` ini (one sub-ms digit at the
     *   default 14). NAN/INF, and the exponent form the cast produces from
     *   10^precision up (1e14 at the default 14) and for tiny values, are
     *   rejected — bcmath rejected them too.
     * - string, or a Stringable such as a Brick\Math number (cast first, as
     *   bcmath's string parameter cast it): bcmath's own grammar — optional
     *   sign, digits, optional fraction, nothing else (no whitespace,
     *   exponent or trailing newline).
     *
     * The `bcmath.scale` ini is ignored: a non-default scale used to append a
     * fraction ("…000.00" at 2), which was never a valid ingest timestamp.
     *
     * Deliberately stricter than bcmath: null, bool and digitless strings
     * (`''`, a lone sign or dot, `'-.'`), which it read as 0 (or 1 for
     * `true`), are rejected.
     */
    private static function nanosecondTimestamp(mixed $ms): ?string
    {
        if (is_int($ms)) {
            return $ms === 0 ? '0' : $ms . '000000';
        }
        if (is_float($ms) || $ms instanceof \Stringable) {
            // The cast bcmath was handed. NAN and INF cast to "NAN"/"INF" and
            // fail the grammar below; a throwing __toString is buildEntry's.
            $ms = (string) $ms;
        } elseif (!is_string($ms)) {
            return null;
        }
        // `\z`, not `$`: `$` would accept a trailing newline. `[0-9]`, not
        // `\d`, so no locale or Unicode mode could ever widen the digit set.
        if (preg_match('/^([+-]?)([0-9]*)(?:\.([0-9]*))?\z/', $ms, $m) !== 1 || $m[2] . ($m[3] ?? '') === '') {
            return null;
        }
        $digits = ltrim($m[2] . substr(str_pad($m[3] ?? '', 6, '0'), 0, 6), '0');

        return $digits === '' ? '0' : ($m[1] === '-' ? '-' : '') . $digits;
    }

    /**
     * Builds one queued entry, or reports the reason it could not be built and
     * returns null.
     *
     * Everything here runs on the caller's request path over the caller's own
     * values: a `JsonSerializable` in `$data` whose `jsonSerialize()` raises,
     * a supplied `timestampProvider` that raises. `json_encode` returning
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
        $timestampNs = self::nanosecondTimestamp($timestampMs);
        if ($timestampNs === null) {
            $this->reject(self::badTimestampMessage($timestampMs));
            return null;
        }

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

    /** Why a `timestampProvider` value was rejected; same text in every mode. */
    private static function badTimestampMessage(mixed $timestampMs): string
    {
        return 'Failed to send log: log entry could not be built: timestampProvider must return'
            . ' epoch milliseconds as an int, float, numeric string or Stringable, got '
            . match (true) {
                // The cast is what was parsed, so it is what explains the rejection.
                is_float($timestampMs) => 'float ' . $timestampMs,
                is_string($timestampMs) => 'non-numeric string',
                default => get_debug_type($timestampMs),
            };
    }

    /**
     * Stdout mode: builds the line and hands it to the sink, or reports why
     * not. Never throws — the same guard as buildEntry(), since everything
     * here runs over the caller's values on the caller's request path.
     *
     * @param array<mixed> $data
     */
    private function writeStdoutLine(string $apiKey, string $level, string $message, array $data): void
    {
        try {
            $line = $this->composeStdoutLine($apiKey, $level, $message, $data);
        } catch (\Throwable $e) {
            $this->reject('Failed to send log: log entry could not be built: ' . $e->getMessage());
            return;
        }

        if ($line !== null) {
            $this->emitStdoutLine($line);
        }
    }

    /**
     * One stdout line (spec § Pipeline profile, "Stdout line"): the envelope,
     * then the line fields, then exactly one `"\n"` — or null after reporting
     * why it could not be built.
     *
     * The envelope and the line object are serialised SEPARATELY and the two
     * texts joined, so nothing in the caller's data can move ahead of
     * `partnerapi_line`: the line keeps the byte-exact prefix
     * `{"partnerapi_line":"1.6.0",` that collectors route on.
     *
     * The line object is built by plain key assignment — array_replace()
     * semantics — and NEVER `array_merge()`, which renumbers integer keys:
     * caller data `['2024' => 'x']` (PHP stores the key as int 2024) would be
     * written as `"0":"x"`. Assignment keeps every key as given, lets data
     * override a context field in place, and drops reserved keys in the same
     * pass.
     *
     * @param array<mixed> $data
     */
    private function composeStdoutLine(string $apiKey, string $level, string $message, array $data): ?string
    {
        $timestampMs = ($this->timestampProvider)();
        $timestampNs = self::nanosecondTimestamp($timestampMs);
        if ($timestampNs === null) {
            $this->reject(self::badTimestampMessage($timestampMs));
            return null;
        }

        // Resolved once, so every field comes from the same scope (PAPI-5336).
        $context = $this->activeScope()->context;

        $envelope = [
            'partnerapi_line' => self::STDOUT_LINE_VERSION,
            'partnerapi_partner_ref' => $this->partnerReference($apiKey),
            'partnerapi_timestamp' => $timestampNs,
            // The CALL's level — the push path's `labels.level`. The line's
            // own `level` below is data the caller may override.
            'partnerapi_level' => $level,
        ];
        foreach (self::STDOUT_ENVELOPE_FIELDS as $field => $key) {
            $value = $context[$field] ?? null;
            if ($value !== null && $value !== '') {
                $envelope[$key] = $value;
            }
        }

        // Unset context fields (absent or null) are omitted; caller data is
        // written as given, null included.
        $line = ['level' => $level, 'message' => $message];
        if (($context['partnerId'] ?? null) !== null) {
            $line['partnerId'] = $context['partnerId'];
        }
        foreach (self::STDOUT_CONTEXT_FIELDS as $field => $key) {
            if (($context[$field] ?? null) !== null) {
                $line[$key] = $context[$field];
            }
        }
        foreach ($data as $key => $value) {
            // The envelope prefix is reserved at the top level: a caller can
            // neither overwrite the reference or marker nor add envelope keys.
            if (is_string($key) && str_starts_with($key, self::STDOUT_RESERVED_PREFIX)) {
                continue;
            }
            $line[$key] = $value;
        }

        $envelopeJson = json_encode($envelope, self::STDOUT_JSON_FLAGS);
        if ($envelopeJson === false) {
            $this->reject('Failed to send log: log entry could not be serialised: ' . json_last_error_msg());
            return null;
        }
        $lineJson = json_encode($line, self::STDOUT_JSON_FLAGS);
        if ($lineJson === false) {
            $this->reject('Failed to send log: log entry could not be serialised: ' . json_last_error_msg());
            return null;
        }
        // `$line` always holds the string keys `level` and `message`, so it
        // encodes as a non-empty object; anything else would corrupt the join.
        if (!str_starts_with($lineJson, '{"')) {
            throw new \UnexpectedValueException('line fields did not encode as a JSON object');
        }

        return substr($envelopeJson, 0, -1) . ',' . substr($lineJson, 1) . "\n";
    }

    /** The v1 partner reference for `$apiKey` under this logger's token, cached. */
    private function partnerReference(string $apiKey): string
    {
        if (isset($this->partnerReferences[$apiKey])) {
            return $this->partnerReferences[$apiKey];
        }
        if (count($this->partnerReferences) >= self::MAX_CACHED_REFERENCES) {
            $this->partnerReferences = [];
        }

        return $this->partnerReferences[$apiKey] = PartnerReference::v1($this->tenantToken, $apiKey);
    }

    /**
     * Hands one complete line to the sink: ONE call, or ONE `fwrite()`, so
     * lines from this process never interleave (spec: atomicity is per
     * process).
     *
     * A short write is not retried. PHP's stream layer already keeps writing
     * after a partial `write(2)` until the OS reports an error, so a short
     * count means the sink failed mid-line, and a second write could not be
     * atomic anyway. Instead one `"\n"` is attempted, so the fragment ends
     * where it stopped and the NEXT line still starts with the marker prefix
     * (ingest drops the fragment as `unparseable_line`). The line is reported
     * as lost, with the OS's own error where PHP raised one.
     */
    private function emitStdoutLine(string $line): void
    {
        $context = self::executionContext();
        if (isset($this->emittingIn[$context])) {
            $this->writeFailed('the sink logged through the logger it is writing for', null);
            return;
        }
        $this->emittingIn[$context] = true;

        $failure = null;
        $cause = null;
        try {
            if ($this->sinkCallable !== null) {
                ($this->sinkCallable)($line);
                $this->deliveredTotal++;
            } else {
                $stream = $this->sinkStream();
                $length = strlen($line);
                [$written, $error] = self::capturingErrors(static fn () => fwrite($stream, $line));
                if ($written === $length) {
                    $this->deliveredTotal++;
                } else {
                    if (is_int($written) && $written > 0) {
                        self::capturingErrors(static fn () => fwrite($stream, "\n"));
                    }
                    $wrote = sprintf('wrote %d of %d bytes', is_int($written) ? $written : 0, $length);
                    $failure = $error !== null ? "{$error} ({$wrote})" : $wrote;
                }
            }
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
            $cause = $e;
        } finally {
            unset($this->emittingIn[$context]);
        }

        // After the guard is released: an `onError` that logs a line about
        // this failure is not the sink re-entering the logger.
        if ($failure !== null) {
            $this->writeFailed($failure, $cause);
        }
    }

    /**
     * Runs `$fn` with a temporary error handler that keeps the message of any
     * warning it raises (the OS's EPIPE / ENOSPC text, from `fwrite()` /
     * `fopen()`) instead of letting it reach the host's handler — which a
     * framework would promote to an exception — and restores the previous
     * handler whatever happens. Unlike `@` plus `error_get_last()`, this
     * works under a framework's own handler and leaves the host's last-error
     * state alone.
     *
     * @template T
     * @param callable(): T $fn
     * @return array{0: T, 1: string|null}
     */
    private static function capturingErrors(callable $fn): array
    {
        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return [$result, $error];
    }

    /**
     * Who is running right now, for the re-entrancy guards: the current
     * Fiber, and the Swoole coroutine when that extension is loaded (it is
     * optional, never required). Two requests interleaved by either are two
     * contexts; a sink that logs through its own logger is the same one.
     */
    private static function executionContext(): string
    {
        $fiber = \Fiber::getCurrent();
        $key = $fiber === null ? 'main' : 'fiber:' . spl_object_id($fiber);

        if (self::$coroutineId === null) {
            self::$coroutineId = false;
            foreach (['Swoole\\Coroutine', 'OpenSwoole\\Coroutine'] as $class) {
                if (class_exists($class, false) && is_callable([$class, 'getCid'])) {
                    self::$coroutineId = \Closure::fromCallable([$class, 'getCid']);
                    break;
                }
            }
        }
        if (self::$coroutineId !== false) {
            $key .= ':co:' . (self::$coroutineId)();
        }

        return $key;
    }

    /**
     * The stream behind a URI/path sink, opened in append mode on first use
     * and kept open. A file-path sink switches to a fresh stream when its
     * file was rotated away (see {@see self::SINK_ROTATION_CHECK_MS} and
     * {@see self::reopenRotatedSink()}). Throws when the FIRST open fails;
     * the next line tries again.
     *
     * @return resource
     */
    private function sinkStream()
    {
        if ($this->sinkStream !== null && $this->sinkIsPath && $this->sinkRotationDue() && $this->sinkMayHaveRotated()) {
            $this->reopenRotatedSink();
        }
        if ($this->sinkStream !== null) {
            return $this->sinkStream;
        }

        $uri = (string) $this->sinkUri;
        [$stream, $error] = self::capturingErrors(static fn () => fopen($uri, 'ab'));
        if ($stream === false) {
            throw new \RuntimeException("could not open {$uri}: " . ($error ?? 'unknown error'));
        }
        if ($this->sinkIsPath) {
            $this->sinkIdentity = self::streamIdentity($stream);
            $this->sinkCheckedAt = $this->sinkClockMs();
        }

        return $this->sinkStream = $stream;
    }

    /** Whether a rotation check is due; at most once per SINK_ROTATION_CHECK_MS. */
    private function sinkRotationDue(): bool
    {
        $now = $this->sinkClockMs();
        if ($now === null || ($this->sinkCheckedAt !== null && $now - $this->sinkCheckedAt < self::SINK_ROTATION_CHECK_MS)) {
            return false;
        }
        $this->sinkCheckedAt = $now;

        return true;
    }

    /**
     * Whether the path may no longer name the file the open stream writes
     * to: `stat()` fails, or names another file (dev/inode). `copytruncate`
     * keeps the same file, so it never gets this far.
     *
     * A failed `stat()` is only a candidate: PHP does not say why it failed,
     * and an unreadable directory fails it as surely as a deleted file does.
     * {@see self::reopenRotatedSink()} decides.
     */
    private function sinkMayHaveRotated(): bool
    {
        if ($this->sinkIdentity === null) {
            return false;
        }
        $path = (string) $this->sinkUri;
        clearstatcache(true, $path);
        [$stat] = self::capturingErrors(static fn () => stat($path));

        return $stat === false || [(int) $stat['dev'], (int) $stat['ino']] !== $this->sinkIdentity;
    }

    /**
     * Opens the path afresh and switches to it only when that open succeeds
     * AND names a different file than the one being written — the path was
     * renamed or deleted away (logrotate's default `create` mode; a deleted
     * path is recreated by the open). Otherwise the current stream, which
     * still writes, is kept, nothing is reported (no line was lost), and the
     * check runs again at the next interval: an unreadable directory, a new
     * file that cannot be opened yet, a full inode table.
     */
    private function reopenRotatedSink(): void
    {
        $uri = (string) $this->sinkUri;
        [$fresh] = self::capturingErrors(static fn () => fopen($uri, 'ab'));
        if ($fresh === false) {
            return;
        }

        $identity = self::streamIdentity($fresh);
        if ($identity === null || $identity === $this->sinkIdentity) {
            self::capturingErrors(static fn () => fclose($fresh));

            return;
        }

        $old = $this->sinkStream;
        $this->sinkStream = $fresh;
        $this->sinkIdentity = $identity;
        self::capturingErrors(static fn () => fclose($old));
    }

    /**
     * @param resource $stream
     * @return array{0: int, 1: int}|null (dev, inode), or null when unknown.
     */
    private static function streamIdentity($stream): ?array
    {
        [$stat] = self::capturingErrors(static fn () => fstat($stream));

        return is_array($stat) ? [(int) $stat['dev'], (int) $stat['ino']] : null;
    }

    /** This logger's clock, or null when a caller-supplied one throws. */
    private function sinkClockMs(): ?float
    {
        try {
            return $this->clockMs();
        } catch (\Throwable) {
            return null;
        }
    }

    /** `flush()` in stdout mode: flush the sink's stream, if one is open. */
    private function flushSink(): void
    {
        try {
            if (is_resource($this->sinkStream)) {
                $stream = $this->sinkStream;
                self::capturingErrors(static fn () => fflush($stream));
            }
        } catch (\Throwable) {
            // flush() never throws.
        }
    }

    /**
     * Counts one line lost to the sink, and reports it — at most once per
     * {@see self::WRITE_FAILED_REPORT_INTERVAL_MS}; the report carries every
     * loss since the last one, and `flush()` reports what is held back.
     *
     * A line the `onError` hook itself logged while handling a write failure
     * is counted and left for the next report: reporting it now would call
     * the hook again, forever, for as long as the sink keeps failing.
     */
    private function writeFailed(string $detail, ?\Throwable $cause): void
    {
        $this->droppedTotal++;
        $this->writeLostTotal++;
        $this->writeLostUnreported++;
        $this->lastWriteFailure = $detail;
        $this->lastWriteFailureCause = $cause;

        if (isset($this->reportingWriteFailureIn[self::executionContext()])) {
            return;
        }
        $now = $this->sinkClockMs();
        if (
            $now !== null
            && $this->writeLostReportedAt !== null
            && $now - $this->writeLostReportedAt < self::WRITE_FAILED_REPORT_INTERVAL_MS
        ) {
            return;
        }
        $this->reportUnreportedWriteFailures($now);
    }

    private function reportUnreportedWriteFailures(?float $now = null): void
    {
        $context = self::executionContext();
        if ($this->writeLostUnreported === 0 || isset($this->reportingWriteFailureIn[$context])) {
            return;
        }
        $count = $this->writeLostUnreported;
        $this->writeLostUnreported = 0;
        $this->writeLostReportedAt = $now ?? $this->sinkClockMs();

        $this->reportingWriteFailureIn[$context] = true;
        try {
            // The TypeScript SDK's lead-in for the same failure.
            $this->report(new LoggerErrorEvent(
                LoggerErrorEvent::REASON_WRITE_FAILED,
                'Failed to write log: ' . $this->lastWriteFailure . ($count === 1 ? '' : sprintf(
                    ' — %d lines lost since the last report, %d in total',
                    $count,
                    $this->writeLostTotal,
                )),
                $count,
                $this->droppedTotal,
                null,
                null,
                null,
                $this->lastWriteFailureCause,
            ));
        } finally {
            unset($this->reportingWriteFailureIn[$context]);
        }
    }

    /**
     * Stdout mode's `logRequest()` data: as the push path's, except that
     * `headers` is omitted when the call supplied none (push writes `[]`), an
     * empty map is written `{}`, and a header value equal to the app key is
     * redacted.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function stdoutRequestData(string $apiKey, array $request, string $correlationId): array
    {
        $data = ['method' => $request['method'], 'path' => $request['path']];
        if (($request['headers'] ?? null) !== null) {
            $data['headers'] = $this->stdoutHeaders($request['headers'], $apiKey);
        }
        $data['correlation_id'] = $correlationId;
        if (array_key_exists('body', $request)) {
            $data['body'] = $request['body'];
        }

        return $data;
    }

    /**
     * Stdout mode's `logResponse()` data: `headers` and `body` are omitted when
     * the call did not supply them (push writes both as null); an explicit
     * `body: null` is written as null.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function stdoutResponseData(string $apiKey, array $response): array
    {
        $data = ['status_code' => $response['statusCode']];
        if (($response['headers'] ?? null) !== null) {
            $data['headers'] = $this->stdoutHeaders($response['headers'], $apiKey);
        }
        if (array_key_exists('body', $response)) {
            $data['body'] = $response['body'];
        }
        $data['duration_ms'] = $response['duration'];
        $data['correlation_id'] = $response['correlationId'];

        return $data;
    }

    /**
     * Header redaction for a stdout line: by name, as on the push path, and
     * also any VALUE exactly equal to the call's app key, whatever the header
     * is called (spec § "What is, and is not, on a line"). A multi-value
     * header (a list, as PSR-7 hands them) is checked per value.
     *
     * Returned as an object so an empty map is written `{}`, never `[]`, and
     * a header named `"0"` stays a key rather than turning the map into a
     * JSON list.
     *
     * @param array<array-key, mixed> $headers
     */
    private function stdoutHeaders(array $headers, string $apiKey): \stdClass
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                $value = '[REDACTED]';
            } elseif ($value === $apiKey) {
                $value = '[REDACTED]';
            } elseif (is_array($value)) {
                foreach ($value as $i => $item) {
                    if ($item === $apiKey) {
                        $value[$i] = '[REDACTED]';
                    }
                }
            }
            $redacted[$name] = $value;
        }

        // The cast, not property assignment: it also carries a header named
        // `""`, which `$object->{''}` cannot.
        return (object) $redacted;
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
     * Puts this logger on the process-wide shutdown drain, registering that
     * drain's one `register_shutdown_function` hook if it is not already
     * pending. Called on every buffered entry; cheap when already queued.
     *
     * Lazy rather than constructor-time, so a logger that never buffers is
     * never visited. The hook is a `static` closure and the queue is a
     * `WeakMap`, so on a long-lived worker neither keeps a logger alive
     * (FLT-1522); on a per-request SAPI the queue also holds it strongly.
     */
    private function armShutdownFlush(): void
    {
        if (!$this->flushOnShutdown) {
            return;
        }

        self::$awaitingShutdown ??= new \WeakMap();
        self::$awaitingShutdown[$this] = true;
        if (!self::holdsLoggersWeakly()) {
            self::$heldUntilShutdown[spl_object_id($this)] = $this;
        }

        // An entry buffered WHILE the hook drains — from a drain's own
        // `onError`, typically, which can log on the very logger whose
        // delivery just failed — is queued but registers nothing: with ingest
        // down, a fresh hook per round would fail, log, and register again
        // forever, and the process would never exit. 2.1.0 behaved the same
        // way. A later entry (from a shutdown function registered after this
        // one, say) registers a fresh hook, which drains it and those.
        if (self::$shutdownHookPending || self::$shutdownHookRunning) {
            return;
        }
        self::$shutdownHookPending = true;

        $hook = static function (): void {
            self::drainAllOnShutdown();
        };
        if (self::$registerShutdownHook !== null) {
            (self::$registerShutdownHook)($hook);
        } else {
            register_shutdown_function($hook);
        }
    }

    /**
     * Whether the shutdown queue holds loggers weakly and a dropped logger
     * drains as it is destroyed: true everywhere but a per-request SAPI. The
     * one place that decision is made.
     */
    private static function holdsLoggersWeakly(): bool
    {
        return !in_array(self::$sapi ?? PHP_SAPI, self::PER_REQUEST_SAPIS, true);
    }

    /**
     * The process-wide end-of-request drain: every logger still alive with
     * entries queued since its last `shutdown()`. Registered by
     * {@see self::armShutdownFlush()}.
     */
    private static function drainAllOnShutdown(): void
    {
        // PHP runs shutdown functions in registration order, and this one is
        // registered on the FIRST log call — so anything the application
        // registered later runs after this drain. An entry logged from there
        // (a fatal-error handler, a debug bar) would otherwise sit in the
        // buffer until the process died, delivered by nothing and reported to
        // no one. Clearing the flag lets that entry register a fresh hook: a
        // function registered *during* shutdown still runs. Entries logged
        // while this one runs do not (see armShutdownFlush()).
        self::$shutdownHookPending = false;
        self::$shutdownHookRunning = true;

        try {
            // Copied first: a drain runs caller code (`onError`), and the
            // list being visited must not change under it. These references
            // are strong only for the length of this call.
            $loggers = [];
            foreach (self::$awaitingShutdown ?? [] as $logger => $_) {
                $loggers[] = $logger;
            }
            self::$awaitingShutdown = null;
            self::$heldUntilShutdown = [];

            if ($loggers === []) {
                return;
            }

            // Hand the response to the client BEFORE talking to ingest, so
            // delivery costs the partner's request nothing — once, not per
            // logger. If any logger asks for it the response ends here, so a
            // logger that opted out is drained after it too.
            foreach ($loggers as $logger) {
                if ($logger->finishRequestOnShutdown) {
                    self::finishResponse();
                    break;
                }
            }

            // One budget for the whole drain: each logger's `drainDeadlineMs`
            // counts from here — after the response is finished, as for a
            // single logger before 2.1.1 — not from its own turn, so N
            // loggers still finish within the longest of their budgets: it is
            // the FPM request's `request_terminate_timeout` they all spend.
            $startedAt = [];
            foreach ($loggers as $i => $logger) {
                try {
                    $startedAt[$i] = $logger->clockMs();
                } catch (\Throwable) {
                    // A caller-supplied `clock` that throws. drain() reads it
                    // again, inside its own try, and reports the loss there.
                    $startedAt[$i] = null;
                }
            }

            foreach ($loggers as $i => $logger) {
                // Belongs to no request (PAPI-5336, rule 4) — and an `exit`
                // inside runWithContext() skips the `finally` that would have
                // closed its scope, so the stack may still name one here.
                // flushFrom() never throws, so one logger cannot stop the next.
                $logger->atRootScope(fn () => $logger->flushFrom($startedAt[$i]));
            }
        } finally {
            self::$shutdownHookRunning = false;
        }
    }

    /**
     * `fastcgi_finish_request()`, at most once per process. Returns false if
     * the framework already called it (Symfony/Laravel do) — harmless.
     */
    private static function finishResponse(): void
    {
        if (self::$responseFinished) {
            return;
        }
        self::$responseFinished = true;

        if (self::$finishResponse !== null) {
            (self::$finishResponse)();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Test seam (FLT-1522); not part of the public API, and may change in any
     * release. Replaces `register_shutdown_function()` and
     * `fastcgi_finish_request()` for the shutdown drain, overrides the SAPI
     * name that decides between weak and strong holding (see
     * {@see self::PER_REQUEST_SAPIS}), and forgets every queued logger and
     * any pending hook, so a test can count registrations and run the hook
     * itself. No arguments restores the real ones; a real hook already
     * registered stays registered and finds nothing queued.
     *
     * @internal
     *
     * @param (\Closure(callable): void)|null $register
     * @param (\Closure(): void)|null $finishResponse
     */
    public static function overrideShutdownHook(
        ?\Closure $register = null,
        ?\Closure $finishResponse = null,
        ?string $sapi = null,
    ): void {
        self::$registerShutdownHook = $register;
        self::$finishResponse = $finishResponse;
        self::$sapi = $sapi;
        self::$awaitingShutdown = null;
        self::$heldUntilShutdown = [];
        self::$shutdownHookPending = false;
        self::$shutdownHookRunning = false;
        self::$responseFinished = false;
    }

    /**
     * On a long-lived worker, a logger dropped with entries still buffered
     * tries to deliver them now rather than losing them silently: since 2.1.1
     * the shutdown hook no longer keeps it alive to do so at process exit
     * (FLT-1522). Gated on `flushOnShutdown`, like the hook. A logger alive
     * at shutdown is drained by the hook first, so this finds nothing to do.
     * On a per-request SAPI the hook still holds the logger, as in 2.1.0, so
     * this does nothing there.
     *
     * This runs whenever the last reference goes — on a function return, or
     * whenever the cycle collector runs — so it may be mid-request. It
     * therefore costs what the `batchSize` drain costs (the `FINAL` profile):
     * one attempt per chunk, no backoff sleep, bounded as a whole by
     * `autoDrainTimeoutMs`. What it could not deliver is reported through
     * `onError` (`flush-failed`, or `drain-timeout` for chunks the budget
     * never reached) and dropped.
     *
     * Never throws, including on an object whose constructor never ran (a
     * PHPUnit `createMock(Logger::class)`, a subclass that skips
     * `parent::__construct()`): `$buffer` is read first because it is the one
     * property with a default.
     */
    public function __destruct()
    {
        try {
            if ($this->buffer === [] || !$this->flushOnShutdown || !self::holdsLoggersWeakly()) {
                return;
            }

            $this->atRootScope(function (): void {
                try {
                    $this->reportUnreportedUpstreamLost();
                } catch (\Throwable) {
                    // A caller-supplied `clock` that throws. drain() reads it
                    // again, inside its own try, and reports the loss there.
                }
                $this->drain(self::DRAIN_FINAL);
            });
        } catch (\Throwable) {
            // Nothing may escape into whoever dropped the last reference.
        }
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
     * for later; `DRAIN_FINAL` takes the same one attempt and drops what
     * fails; `DRAIN_DIRECT` takes one attempt and raises.
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
        // A FINAL drain with `autoDrainTimeoutMs: 0` (bound disabled) falls
        // back to `requestTimeoutMs` per attempt, rather than to no timeout at
        // all against a blackholed ingest.
        $ceiling = match (true) {
            $profile === self::DRAIN_AUTO => $this->autoDrainTimeoutMs,
            $profile === self::DRAIN_FINAL && $this->autoDrainTimeoutMs > 0 => $this->autoDrainTimeoutMs,
            default => $this->requestTimeoutMs,
        };

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

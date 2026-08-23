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
 * can any longer fail or slow down the partner request the log describes.
 *
 * PHP has no event loop, so a buffered SDK needs an explicit end-of-request
 * drain. There are three:
 *
 * 1. `flush()` — deliver everything buffered at call time. Always available.
 * 2. `batchSize` — reaching it drains automatically (default 100 entries).
 * 3. A `register_shutdown_function` hook, armed on the first buffered entry.
 *    It calls `fastcgi_finish_request()` first where the SAPI provides it, so
 *    the client already has its response before a single byte goes to ingest.
 *
 * Under Laravel the service provider additionally registers a `terminating`
 * callback, which runs earlier than the shutdown hook and works on long-lived
 * workers (Octane, queues) where per-request shutdown functions never fire.
 *
 * The pre-2.0 synchronous behaviour — POST on the call, raise on failure — is
 * still reachable with `['mode' => Logger::MODE_DIRECT]`.
 *
 * `metric()` / `metrics()` are deliberately NOT buffered: a metric submission
 * is an explicit write the caller is entitled to a receipt for, so it still
 * posts synchronously and still raises `LoggerException` on failure.
 */
class Logger
{
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
        'flushOnShutdown',
        'finishRequestOnShutdown',
        'sleeper',
        'randomizer',
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
    private array $context = [];
    private ClientInterface $httpClient;

    /** @var callable(): int */
    private $timestampProvider;

    /** @var callable(LoggerErrorEvent): void */
    private $onError;

    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(): float */
    private $randomizer;

    private string $mode;
    private int $maxBufferSize;
    private int $batchSize;
    private int $maxRetries;
    private int $retryBaseDelayMs;
    private int $retryMaxDelayMs;
    private int $requestTimeoutMs;
    private bool $flushOnShutdown;
    private bool $finishRequestOnShutdown;

    /** @var list<array{apiKey: string, labels: array<string, string>, root: array<string, string>, entry: array{timestamp: string, line: string}}> */
    private array $buffer = [];

    private bool $shutdownArmed = false;
    private bool $draining = false;
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
     *     flushOnShutdown?: bool,
     *     finishRequestOnShutdown?: bool,
     *     sleeper?: callable(int): void,
     *     randomizer?: callable(): float
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
     *   bad request and is dropped immediately. Ignored in direct mode, where
     *   a retry would be a sleep on the caller's request path.
     * - `retryBaseDelayMs` / `retryMaxDelayMs` — equal-jitter backoff window
     *   (defaults 200 / 5000).
     * - `requestTimeoutMs` — per-request deadline (default 5000; `0` disables).
     *   Guzzle has no timeout by default, so without this a blackholed ingest
     *   hangs the flush — and in direct mode, the request itself.
     * - `flushOnShutdown` — arm the `register_shutdown_function` drain
     *   (default true).
     * - `finishRequestOnShutdown` — call `fastcgi_finish_request()` before that
     *   drain where the SAPI has it (default true), so delivery happens after
     *   the client already has its response.
     * - `sleeper` / `randomizer` — backoff seams for tests.
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

        $this->tenantToken = $tenantToken;
        $this->baseUrl = $baseUrl ?? 'https://ingest.partnerapi.com';
        $this->httpClient = $httpClient ?? new Client();
        $this->timestampProvider = $timestampProvider ?? fn () => (int) (microtime(true) * 1000);

        $this->mode = ($options['mode'] ?? self::MODE_BUFFERED) === self::MODE_DIRECT
            ? self::MODE_DIRECT
            : self::MODE_BUFFERED;

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
        $this->flushOnShutdown = (bool) ($options['flushOnShutdown'] ?? true);
        $this->finishRequestOnShutdown = (bool) ($options['finishRequestOnShutdown'] ?? true);

        $this->sleeper = $options['sleeper'] ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
        $this->randomizer = $options['randomizer'] ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function setContext(array $fields): void
    {
        $this->context = array_merge($this->context, $fields);
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

        $this->info($apiKey, 'Outgoing response', $data);
    }

    /**
     * Delivers everything buffered at call time.
     *
     * Never throws — failures go to `onError`. Call it wherever entries must
     * have landed before the process moves on: the end of a request handler, a
     * shutdown path, before a long sleep in a worker.
     */
    public function flush(): void
    {
        // `onError` runs inside the drain; a hook that logs through this same
        // logger would otherwise re-enter and post the batch it is reporting on.
        if ($this->draining || $this->buffer === []) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];
        $this->draining = true;

        try {
            foreach ($this->group($batch) as $group) {
                foreach ($this->chunk($group) as $chunk) {
                    $this->postBatch($chunk, false);
                }
            }
        } catch (\Throwable $e) {
            // Belt and braces. `postBatch()` already swallows every delivery
            // failure, so reaching here means something outside that contract
            // threw — a caller-supplied `sleeper`, most plausibly. `flush()`
            // runs from a shutdown function and from the log path, and an
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
     * @return array{buffered: int, delivered: int, dropped: int}
     */
    public function stats(): array
    {
        return [
            'buffered' => count($this->buffer),
            'delivered' => $this->deliveredTotal,
            'dropped' => $this->droppedTotal,
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
            ]);
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
            $this->postBatch([$queued], true);
            return;
        }

        $this->armShutdownFlush();
        $this->buffer[] = $queued;

        $overflow = count($this->buffer) - $this->maxBufferSize;
        if ($overflow > 0) {
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

        if ($this->batchSize > 0 && count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
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

        $contextDefaults = array_filter([
            'request_id' => $this->context['requestId'] ?? null,
            'path' => $this->context['path'] ?? null,
            'method' => $this->context['method'] ?? null,
            'status_code' => $this->context['statusCode'] ?? null,
            'duration_ms' => $this->context['duration'] ?? null,
            'correlation_id' => $this->context['correlationId'] ?? null,
        ], fn ($v) => $v !== null);

        $lineBase = ['level' => $level, 'message' => $message];
        if (($this->context['partnerId'] ?? null) !== null) {
            $lineBase['partnerId'] = $this->context['partnerId'];
        }

        // Merge context defaults (nulls already filtered) then user data (preserve nulls)
        $lineData = array_merge($lineBase, $contextDefaults, $data);

        // `$data` is whatever the caller passed. Invalid UTF-8 or a recursive
        // structure makes `json_encode` return false — which, before 2.0.0,
        // shipped a literal `false` as the log line.
        $line = json_encode($lineData, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $this->reject('Failed to send log: log entry could not be serialised: ' . json_last_error_msg());
            return null;
        }

        // Labels and upstream attribution are snapshotted here, not at drain
        // time — a later `setContext` must not retro-label queued entries.
        $labels = ['level' => $level];
        if (isset($this->context['partnerId'])) {
            $labels['partnerId'] = $this->context['partnerId'];
        }
        // PAPI-687: direction rides in labels so ingest can promote it to a
        // Loki stream label. Omitted when unset — ingest defaults to 'inbound'.
        if (isset($this->context['direction'])) {
            $labels['direction'] = $this->context['direction'];
        }

        // PAPI-687: upstream attribution is request-level, not a stream label
        // (base URLs are high-cardinality). Ingest folds these into the line.
        $root = [];
        if (isset($this->context['upstreamIntegration'])) {
            $root['upstream_integration'] = $this->context['upstreamIntegration'];
        }
        if (isset($this->context['upstreamBaseUrl'])) {
            $root['upstream_base_url'] = $this->context['upstreamBaseUrl'];
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

    /** Hands an error to the caller's hook without letting it escape. */
    private function report(LoggerErrorEvent $event): void
    {
        try {
            ($this->onError)($event);
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

        register_shutdown_function(function (): void {
            // Hand the response to the client BEFORE talking to ingest, so
            // delivery costs the partner's request nothing. Returns false if
            // the framework already called it (Symfony/Laravel do) — harmless.
            if ($this->finishRequestOnShutdown && function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->flush();
        });
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
     * Posts one batch, retrying transient failures, then gives up and reports.
     *
     * @param list<array<string, mixed>> $chunk
     * @param bool $throw Direct mode: raise instead of reporting, single attempt.
     */
    private function postBatch(array $chunk, bool $throw): void
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
                $this->post('/logs', $first['apiKey'], $body);
                $this->deliveredTotal += $count;
                return;
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

                if (!$throw && $retryable && $attempt < $this->maxRetries) {
                    ($this->sleeper)($this->backoffDelayMs($attempt));
                    continue;
                }

                // v1 wording, kept so anything matching on it still matches.
                $message = 'Failed to send log: ' . $e->getMessage();

                if ($throw) {
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
                return;
            }
        }
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
     */
    private function post(string $path, string $apiKey, array $body): void
    {
        $options = [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'x-tenant-token' => $this->tenantToken,
            ],
        ];

        if ($this->requestTimeoutMs > 0) {
            // Guzzle defaults to no timeout at all: a blackholed ingest would
            // otherwise hang the flush, and in direct mode the caller with it.
            $options['timeout'] = $this->requestTimeoutMs / 1000;
            $options['connect_timeout'] = $this->requestTimeoutMs / 1000;
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

<?php

return [
    'tenant_token' => env('PARTNER_API_TENANT_TOKEN'),
    'base_url' => env('PARTNER_API_BASE_URL', 'https://ingest.partnerapi.com'),

    // Delivery profile. 'buffered' (default) queues log entries in-process and
    // drains them after the response has been sent, so an ingest outage can
    // never fail or slow down the request being logged. 'direct' restores the
    // pre-2.0 behaviour: every log call POSTs synchronously and throws
    // PartnerApi\Logger\LoggerException on failure. 'stdout' writes each log
    // call as one JSON line to stdout_sink for your own log pipeline (an
    // OpenTelemetry Collector, Fluent Bit, Vector) to deliver, and sends
    // nothing itself; see the readme, "Pipeline delivery (stdout mode)".
    'mode' => env('PARTNER_API_LOG_MODE', 'buffered'),

    // Where 'stdout' mode writes: php://stdout, php://stderr or an ABSOLUTE
    // file path, opened in append mode (reopened when rotated away). Under
    // PHP-FPM worker output is discarded unless the pool sets
    // catch_workers_output, and then FPM splits lines longer than log_limit;
    // under `octane:start` the server re-renders worker output and the lines
    // lose their prefix. Under FPM or Octane, set this to a file your
    // collector tails. See the readme, "PHP-FPM" and "Octane". Ignored in the
    // other modes; in stdout mode any value that is not a non-empty string
    // means php://stdout.
    'stdout_sink' => env('PARTNER_API_LOG_STDOUT_SINK', 'php://stdout'),

    // Buffered entries that trigger an automatic drain mid-request. 0 disables
    // the size trigger, leaving exactly one POST at the end of the request.
    'batch_size' => (int) env('PARTNER_API_LOG_BATCH_SIZE', 100),

    // Entries held before the oldest are dropped to make room. Bounds memory
    // when ingest is unreachable.
    'max_buffer_size' => (int) env('PARTNER_API_LOG_MAX_BUFFER_SIZE', 1000),

    // Retries after the first attempt, per batch. Only network faults, 408,
    // 429 and 5xx are retried. Ignored in 'direct' mode.
    'max_retries' => (int) env('PARTNER_API_LOG_MAX_RETRIES', 3),
    'retry_base_delay_ms' => (int) env('PARTNER_API_LOG_RETRY_BASE_DELAY_MS', 200),
    'retry_max_delay_ms' => (int) env('PARTNER_API_LOG_RETRY_MAX_DELAY_MS', 5000),

    // Per-request deadline in milliseconds. 0 disables it (Guzzle's own
    // default), which lets a blackholed ingest hang the flush.
    'request_timeout_ms' => (int) env('PARTNER_API_LOG_REQUEST_TIMEOUT_MS', 5000),

    // The deadline for the ONE drain a caller ever waits on: the batch_size
    // trigger, which runs inside a log call. One attempt, no backoff, and a
    // retryable failure is handed back to the buffer instead of retried here.
    // This is the most a single log call can cost the request.
    'auto_drain_timeout_ms' => (int) env('PARTNER_API_LOG_AUTO_DRAIN_TIMEOUT_MS', 1000),

    // Total budget for one flush()/terminate/shutdown drain, across every
    // group, chunk, retry and backoff. 0 disables the bound. The shutdown
    // drain runs inside the FPM request that already answered the client, so
    // keep this well under the pool's request_terminate_timeout — otherwise
    // FPM kills the worker mid-drain and the buffer dies with it. Entries
    // still undelivered when it runs out are reported as 'drain-timeout'.
    'drain_deadline_ms' => (int) env('PARTNER_API_LOG_DRAIN_DEADLINE_MS', 5000),

    // Drain anything still buffered from a register_shutdown_function, as a
    // fallback for requests that never reach Laravel's terminate() path, and
    // when a logger is destroyed with entries still buffered.
    'flush_on_shutdown' => (bool) env('PARTNER_API_LOG_FLUSH_ON_SHUTDOWN', true),

    // Call fastcgi_finish_request() before that fallback drain, where the SAPI
    // provides it, so the client has its response before ingest is contacted.
    'finish_request_on_shutdown' => (bool) env('PARTNER_API_LOG_FINISH_REQUEST', true),
];

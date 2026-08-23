<?php

return [
    'tenant_token' => env('PARTNER_API_TENANT_TOKEN'),
    'base_url' => env('PARTNER_API_BASE_URL', 'https://ingest.partnerapi.com'),

    // Delivery profile. 'buffered' (default) queues log entries in-process and
    // drains them after the response has been sent, so an ingest outage can
    // never fail or slow down the request being logged. 'direct' restores the
    // pre-2.0 behaviour: every log call POSTs synchronously and throws
    // PartnerApi\Logger\LoggerException on failure.
    'mode' => env('PARTNER_API_LOG_MODE', 'buffered'),

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

    // Drain anything still buffered from a register_shutdown_function, as a
    // fallback for requests that never reach Laravel's terminate() path.
    'flush_on_shutdown' => (bool) env('PARTNER_API_LOG_FLUSH_ON_SHUTDOWN', true),

    // Call fastcgi_finish_request() before that fallback drain, where the SAPI
    // provides it, so the client has its response before ingest is contacted.
    'finish_request_on_shutdown' => (bool) env('PARTNER_API_LOG_FINISH_REQUEST', true),
];

<?php

namespace PartnerApi\Logger\Laravel;

use Illuminate\Support\ServiceProvider;
use PartnerApi\Logger\Logger;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/partner-logger.php', 'partner-logger');

        $this->app->singleton(Logger::class, function ($app) {
            $config = $app['config']['partner-logger'];

            return new Logger(
                tenantToken: $config['tenant_token'],
                baseUrl: $config['base_url'] ?? null,
                options: [
                    'mode' => $config['mode'] ?? Logger::MODE_BUFFERED,
                    'batchSize' => (int) ($config['batch_size'] ?? 100),
                    'maxBufferSize' => (int) ($config['max_buffer_size'] ?? 1000),
                    'maxRetries' => (int) ($config['max_retries'] ?? 3),
                    'retryBaseDelayMs' => (int) ($config['retry_base_delay_ms'] ?? 200),
                    'retryMaxDelayMs' => (int) ($config['retry_max_delay_ms'] ?? 5000),
                    'requestTimeoutMs' => (int) ($config['request_timeout_ms'] ?? 5000),
                    'autoDrainTimeoutMs' => (int) ($config['auto_drain_timeout_ms'] ?? 1000),
                    'drainDeadlineMs' => (int) ($config['drain_deadline_ms'] ?? 5000),
                    'flushOnShutdown' => (bool) ($config['flush_on_shutdown'] ?? true),
                    'finishRequestOnShutdown' => (bool) ($config['finish_request_on_shutdown'] ?? true),
                ],
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/partner-logger.php' => config_path('partner-logger.php'),
        ], 'partner-logger-config');

        // Since 2.0.0 log calls buffer instead of posting (see the Logger
        // docblock), so something has to drain the buffer at the end of the
        // request. A `terminating` callback is the right hook: Laravel runs it
        // from `Application::terminate()`, after the response has already been
        // sent — and, unlike a `register_shutdown_function`, it fires once per
        // request on long-lived workers (Octane, queues, `artisan serve`)
        // rather than once when the worker process finally dies.
        //
        // The Logger arms its own shutdown-function fallback as well, and a
        // second flush on an empty buffer is a no-op, so a request that
        // somehow bypasses `terminate()` still delivers.
        // Lumen's container has no terminate() lifecycle; there the Logger's
        // own shutdown-function fallback is the only drain, which is fine.
        if (!method_exists($this->app, 'terminating')) {
            return;
        }

        $this->app->terminating(function () {
            // Resolving here would construct a Logger for every request that
            // never logged, purely to flush nothing.
            if ($this->app->resolved(Logger::class)) {
                $this->app->make(Logger::class)->flush();
            }
        });
    }
}

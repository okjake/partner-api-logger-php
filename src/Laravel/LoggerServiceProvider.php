<?php

namespace PartnerApi\Logger\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use PartnerApi\Logger\Logger;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/partner-logger.php', 'partner-logger');

        $this->app->singleton(Logger::class, function ($app) {
            $config = $app['config']['partner-logger'];
            $mode = $config['mode'] ?? Logger::MODE_BUFFERED;

            $options = [
                'mode' => $mode,
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
            ];

            // Passed only in stdout mode, so a mis-set PARTNER_API_LOG_STDOUT_SINK
            // can never fail construction of a push-mode logger. env() turns
            // `true` / `false` / `null` / `empty` into non-strings: anything
            // but a non-empty string means the default.
            if ($mode === Logger::MODE_STDOUT) {
                $sink = $config['stdout_sink'] ?? null;
                $options['stdoutSink'] = is_string($sink) && trim($sink) !== '' ? $sink : 'php://stdout';
            }

            return new Logger(
                tenantToken: $config['tenant_token'],
                baseUrl: $config['base_url'] ?? null,
                options: $options,
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
        // from `Application::terminate()` after the response has been sent,
        // once per HTTP request — including on Octane, where the Logger's
        // shutdown function fires only when the worker process exits.
        //
        // Lumen's container has no terminate() lifecycle; there the Logger's
        // own shutdown-function drain is the only one, which is fine.
        if (!method_exists($this->app, 'terminating')) {
            return;
        }

        // Drain the logger THIS request used, resolved from the container
        // terminate() runs on — never `$this->app`, the app this provider
        // booted with (FLT-1522). Octane clones that app into a sandbox per
        // request and points `app` at the clone; an un-warmed Logger is first
        // resolved in the sandbox, so the boot-time app has never seen it.
        // `terminate()` invokes callbacks through `$this->call()` (every
        // Laravel from 8 to 13), which injects `Container` as the `app`
        // binding: the sandbox under Octane, the application everywhere else.
        //
        // `static` so the callback captures neither the provider nor its app.
        // Queued jobs are not drained here: `queue:work` terminates once, when
        // the worker exits (see the readme, "Delivery and errors").
        $this->app->terminating(static function (Container $app): void {
            // Resolving here would construct a Logger for every request that
            // never logged, purely to flush nothing.
            if ($app->resolved(Logger::class)) {
                $app->make(Logger::class)->flush();
            }
        });
    }
}

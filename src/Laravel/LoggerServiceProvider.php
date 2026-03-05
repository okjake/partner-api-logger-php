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
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/partner-logger.php' => config_path('partner-logger.php'),
        ], 'partner-logger-config');
    }
}

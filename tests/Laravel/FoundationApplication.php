<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;

require_once __DIR__ . '/foundation-helpers.php';

/**
 * The slice of `Illuminate\Foundation\Application` the service provider
 * depends on, on top of the real `illuminate/container`.
 *
 * Not laravel/framework itself: every 10.x release — the last line that
 * installs on PHP 8.1, this package's floor — carries a Packagist security
 * advisory, and Composer refuses to resolve one (`policy.advisories.block`).
 * The container, its alias resolution and `call()` injection are the real
 * components; the members below are copied from `Application` as of 10.x.
 * `terminating()` / `terminate()` are verbatim and identical from Laravel 8
 * to 13 — re-check them if Laravel changes how terminating callbacks are
 * invoked, because the provider relies on `call()` injecting the container.
 */
final class FoundationApplication extends Container
{
    /** @var list<ServiceProvider> */
    private array $providers = [];

    /** @var list<callable|string> */
    protected $terminatingCallbacks = [];

    public function __construct()
    {
        // Application::registerBaseBindings() …
        static::setInstance($this);
        $this->instance('app', $this);
        $this->instance(Container::class, $this);

        // … and the `app` entry of registerCoreContainerAliases().
        foreach ([
            self::class,
            \Illuminate\Contracts\Container\Container::class,
            \Illuminate\Contracts\Foundation\Application::class,
            \Psr\Container\ContainerInterface::class,
        ] as $alias) {
            $this->alias('app', $alias);
        }
    }

    /** @param class-string<ServiceProvider> $provider */
    public function register(string $provider): ServiceProvider
    {
        $instance = new $provider($this);
        $instance->register();
        $this->providers[] = $instance;

        return $instance;
    }

    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            $this->call([$provider, 'boot']);
        }
    }

    /**
     * @param callable|string $callback
     * @return $this
     */
    public function terminating($callback)
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * @return void
     */
    public function terminate()
    {
        $index = 0;

        while ($index < count($this->terminatingCallbacks)) {
            $this->call($this->terminatingCallbacks[$index]);

            $index++;
        }
    }
}

<?php

namespace PartnerApi\Logger\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PartnerApi\Logger\Logger;

/**
 * @method static void setContext(array $fields)
 * @method static void info(string $apiKey, string $message, array $data = [])
 * @method static void warn(string $apiKey, string $message, array $data = [])
 * @method static void error(string $apiKey, string $message, array $data = [])
 * @method static void debug(string $apiKey, string $message, array $data = [])
 * @method static string logRequest(string $apiKey, array $request)
 * @method static void logResponse(string $apiKey, array $response)
 * @method static void metric(string $apiKey, array $data)
 * @method static void metrics(string $apiKey, string $slug, array $points)
 * @method static void flush()
 * @method static void shutdown()
 * @method static void close()
 * @method static array{buffered: int, delivered: int, dropped: int, upstreamDropped: int} stats()
 * @method static mixed runWithContext(array $context, callable $fn)
 * @method static \PartnerApi\Logger\Logger child(array $context = [])
 * @method static void upstream(array $call)
 * @method static callable upstreamMiddleware(string $name, array $options = [])
 *
 * @see \PartnerApi\Logger\Logger
 */
class PartnerLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Logger::class;
    }
}

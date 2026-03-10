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

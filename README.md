# Partner API Logger SDK for PHP

Send structured logs and metrics to the Partner API ingest service.

## Requirements

- PHP 8.1+

## Installation

```bash
composer require partner-api/logger
```

## Laravel Setup

The package auto-discovers in Laravel. Publish the config file:

```bash
php artisan vendor:publish --tag=partner-logger-config
```

Add your credentials to `.env`:

```env
PARTNER_API_TENANT_TOKEN=tenant_live_xxxxxxxxxxxx
PARTNER_API_BASE_URL=https://ingest.partnerapi.com
```

### Using the Facade

```php
use PartnerApi\Logger\Laravel\Facades\PartnerLogger;

PartnerLogger::info($apiKey, 'Order created', ['orderId' => $order->id]);
```

### Using Dependency Injection

```php
use PartnerApi\Logger\Logger;

class OrderController extends Controller
{
    public function store(Request $request, Logger $logger)
    {
        // ...
        $logger->info($apiKey, 'Order created', ['orderId' => $order->id]);
    }
}
```

## Standalone Usage (without Laravel)

```php
use PartnerApi\Logger\Logger;

$logger = new Logger(
    tenantToken: 'tenant_live_xxxxxxxxxxxx',
    baseUrl: 'https://ingest.partnerapi.com', // optional, this is the default
);
```

## Logging

```php
$logger->info($apiKey, 'Something happened', ['key' => 'value']);
$logger->warn($apiKey, 'Watch out');
$logger->error($apiKey, 'Something broke', ['error' => $e->getMessage()]);
$logger->debug($apiKey, 'Debug details');
```

### Context

Context fields persist across log calls and are included automatically:

```php
$logger->setContext([
    'partnerId' => 'partner-123',
    'requestId' => $request->header('x-request-id'),
]);

// Both logs include partnerId and requestId
$logger->info($apiKey, 'Step 1');
$logger->info($apiKey, 'Step 2');
```

### HTTP Request / Response Logging

```php
// logRequest returns the correlation ID for pairing with the response
$correlationId = $logger->logRequest($apiKey, [
    'method' => $request->method(),
    'path' => $request->path(),
    'headers' => $request->headers->all(),
    'body' => $request->all(),
]);

$logger->logResponse($apiKey, [
    'statusCode' => 200,
    'headers' => ['content-type' => 'application/json'],
    'body' => $responseData,
    'duration' => $durationMs,
    'correlationId' => $correlationId,
]);
```

Sensitive headers (`Authorization`, `Cookie`, `X-API-Key`, etc.) are automatically redacted.

## Metrics

Send business metrics to track over time:

```php
// Single data point
$logger->metric($apiKey, [
    'slug' => 'revenue',
    'timestamp' => '2024-06-01T00:00:00Z',
    'value' => 54000,
    'period' => 'Jun 01',
    'metadata' => ['currency' => 'EUR'], // optional
]);

// Batch
$logger->metrics($apiKey, 'revenue', [
    ['timestamp' => '2024-06-01T00:00:00Z', 'value' => 54000, 'period' => 'Jun 01'],
    ['timestamp' => '2024-06-08T00:00:00Z', 'value' => 55200, 'period' => 'Jun 08'],
    ['timestamp' => '2024-06-15T00:00:00Z', 'value' => 58100, 'period' => 'Jun 15'],
]);
```

## Error Handling

All methods throw `PartnerApi\Logger\LoggerException` on failure:

```php
use PartnerApi\Logger\LoggerException;

try {
    $logger->info($apiKey, 'Hello');
} catch (LoggerException $e) {
    // Handle failure
}
```

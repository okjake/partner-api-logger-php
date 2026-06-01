<!-- doc-type: reference -->

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

## PII Redaction Helper

For call sites that need to redact PII before passing user data into a downstream system whose logs you don't control, the package exposes a public `redactPII()` helper. The ruleset mirrors what the ingest service applies internally — emails, JWTs, Bearer tokens, named API-key prefixes, passwords, phone numbers, credit cards, IPv4/IPv6 addresses, and URL query strings.

```php
use PartnerApi\Logger\RedactPii;
use function PartnerApi\Logger\redactPII;

// Strings (regex pass)
redactPII('contact alice@example.com'); // → 'contact [EMAIL_REDACTED]'
redactPII('Authorization: Bearer abc.def.ghi'); // → 'Authorization: Bearer [TOKEN_REDACTED]'

// Headers (sensitive keys replaced wholesale)
redactPII([
    'Authorization' => 'Bearer secret',
    'X-Api-Key'     => 'sk-livetestkey1234567890',
    'Cookie'        => 'session=abc',
]);
// → ['Authorization' => 'Bearer [TOKEN_REDACTED]', 'X-Api-Key' => '[KEY_REDACTED]', 'Cookie' => '[COOKIE_REDACTED]']

// JSON body / deeply nested arrays
redactPII([
    'user' => [
        'email'       => 'alice@example.com',
        'credentials' => ['password' => 'hunter2', 'refresh_token' => 'rt_xyz'],
    ],
]);
// → ['user' => ['email' => '[EMAIL_REDACTED]', 'credentials' => ['password' => '[PASSWORD_REDACTED]', 'refresh_token' => '[TOKEN_REDACTED]']]]

// Query strings (encoded as associative array)
redactPII(['user' => 'alice', 'api_key' => 'sk-livetestkey1234567890']);
// → ['user' => 'alice', 'api_key' => '[KEY_REDACTED]']
```

Use the static form `RedactPii::redact(...)` when a use-function import is awkward. Options:

```php
RedactPii::redact($input, [
    'preserveStructure'   => true,  // keep sensitive keys with redacted placeholder (default true; false drops the key)
    'redactEmails'        => true,
    'redactApiKeys'       => true,
    'redactTokens'        => true,
    'redactPasswords'     => true,
    'redactPhoneNumbers'  => true,
    'redactCreditCards'   => true,
    'redactIpAddresses'   => true,
    'redactUrls'          => true,
    'redactUuids'         => false, // off by default — opt in for log-line redaction
]);
```

The original input is never mutated — a redacted copy is returned. Scalars (int/float/bool) and `null` pass through untouched.

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

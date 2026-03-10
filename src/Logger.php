<?php

namespace PartnerApi\Logger;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;

class Logger
{
    private string $tenantToken;
    private string $baseUrl;
    private array $context = [];
    private ClientInterface $httpClient;

    /** @var callable(): int */
    private $timestampProvider;

    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-tenant-token',
        'proxy-authorization',
    ];

    public function __construct(
        string $tenantToken,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        ?callable $timestampProvider = null,
    ) {
        $this->tenantToken = $tenantToken;
        $this->baseUrl = $baseUrl ?? 'https://ingest.partnerapi.com';
        $this->httpClient = $httpClient ?? new Client();
        $this->timestampProvider = $timestampProvider ?? fn () => (int) (microtime(true) * 1000);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function setContext(array $fields): void
    {
        $this->context = array_merge($this->context, $fields);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function info(string $apiKey, string $message, array $data = []): void
    {
        $this->sendLog($apiKey, 'info', $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function warn(string $apiKey, string $message, array $data = []): void
    {
        $this->sendLog($apiKey, 'warn', $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function error(string $apiKey, string $message, array $data = []): void
    {
        $this->sendLog($apiKey, 'error', $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function debug(string $apiKey, string $message, array $data = []): void
    {
        $this->sendLog($apiKey, 'debug', $message, $data);
    }

    /**
     * @param array{method: string, path: string, headers?: array<string, string>, body?: mixed} $request
     * @return string The correlation ID used to pair this request with a response
     */
    public function logRequest(string $apiKey, array $request): string
    {
        $headers = $request['headers'] ?? [];
        $correlationId = $headers['x-correlation-id'] ?? Uuid::uuid4()->toString();

        $this->setContext([
            'method' => $request['method'],
            'path' => $request['path'],
            'requestId' => $headers['x-request-id'] ?? null,
            'correlationId' => $correlationId,
        ]);

        $data = [
            'method' => $request['method'],
            'path' => $request['path'],
            'headers' => $this->redactHeaders($headers),
            'correlation_id' => $correlationId,
        ];

        if (array_key_exists('body', $request)) {
            $data['body'] = $request['body'];
        }

        $this->info($apiKey, 'Incoming request', $data);

        return $correlationId;
    }

    /**
     * @param array{statusCode: int, headers?: array<string, string>, body?: mixed, duration: int|float, correlationId: string} $response
     */
    public function logResponse(string $apiKey, array $response): void
    {
        $this->setContext([
            'statusCode' => $response['statusCode'],
            'duration' => $response['duration'],
        ]);

        $this->info($apiKey, 'Outgoing response', [
            'status_code' => $response['statusCode'],
            'headers' => isset($response['headers']) ? $this->redactHeaders($response['headers']) : null,
            'body' => $response['body'] ?? null,
            'duration_ms' => $response['duration'],
            'correlation_id' => $response['correlationId'],
        ]);
    }

    /**
     * @param array{slug: string, timestamp: string, value: int|float, period: string, metadata?: array<string, mixed>} $data
     */
    public function metric(string $apiKey, array $data): void
    {
        $point = [
            'timestamp' => $data['timestamp'],
            'value' => $data['value'],
            'period' => $data['period'],
        ];

        if (isset($data['metadata'])) {
            $point['metadata'] = $data['metadata'];
        }

        $this->metrics($apiKey, $data['slug'], [$point]);
    }

    /**
     * @param array<array{timestamp: string, value: int|float, period: string, metadata?: array<string, mixed>}> $points
     */
    public function metrics(string $apiKey, string $slug, array $points): void
    {
        if (empty($apiKey)) {
            throw new LoggerException('API key is required for metrics');
        }

        if (!is_string($slug) || empty($slug)) {
            throw new LoggerException('slug must be a non-empty string');
        }

        $normalizedSlug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9-]+$/', $normalizedSlug)) {
            throw new LoggerException('slug must contain only lowercase letters, numbers, and hyphens');
        }

        if (empty($points)) {
            throw new LoggerException('points must be a non-empty array');
        }

        if (count($points) > 1000) {
            throw new LoggerException('Maximum 1000 points per request');
        }

        try {
            $this->httpClient->request('POST', "{$this->baseUrl}/metrics", [
                'json' => [
                    'slug' => $slug,
                    'points' => $points,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $apiKey,
                    'x-tenant-token' => $this->tenantToken,
                ],
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            error_log("Failed to send metrics: {$message}");
            throw new LoggerException("Failed to send metrics: {$message}");
        }
    }

    private function sendLog(string $apiKey, string $level, string $message, array $data = []): void
    {
        if (empty($apiKey)) {
            throw new LoggerException('API key is required for logging');
        }

        $timestampMs = ($this->timestampProvider)();
        $timestampNs = bcmul((string) $timestampMs, '1000000');

        $contextDefaults = array_filter([
            'request_id' => $this->context['requestId'] ?? null,
            'path' => $this->context['path'] ?? null,
            'method' => $this->context['method'] ?? null,
            'status_code' => $this->context['statusCode'] ?? null,
            'duration_ms' => $this->context['duration'] ?? null,
            'correlation_id' => $this->context['correlationId'] ?? null,
        ], fn ($v) => $v !== null);

        $lineBase = ['level' => $level, 'message' => $message];
        if (($this->context['partnerId'] ?? null) !== null) {
            $lineBase['partnerId'] = $this->context['partnerId'];
        }

        // Merge context defaults (nulls already filtered) then user data (preserve nulls)
        $lineData = array_merge($lineBase, $contextDefaults, $data);

        $labels = ['level' => $level];
        if (isset($this->context['partnerId'])) {
            $labels['partnerId'] = $this->context['partnerId'];
        }

        $body = [
            'labels' => $labels,
            'entries' => [
                [
                    'timestamp' => $timestampNs,
                    'line' => json_encode($lineData, JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        try {
            $this->httpClient->request('POST', "{$this->baseUrl}/logs", [
                'json' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $apiKey,
                    'x-tenant-token' => $this->tenantToken,
                ],
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            error_log("Failed to send log: {$msg}");
            throw new LoggerException("Failed to send log: {$msg}");
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $key => $value) {
            $redacted[$key] = in_array(strtolower($key), self::SENSITIVE_HEADERS, true)
                ? '[REDACTED]'
                : $value;
        }
        return $redacted;
    }
}

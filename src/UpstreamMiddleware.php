<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The Guzzle middleware {@see Logger::upstreamMiddleware()} returns: records
 * every request sent through it as an upstream call (PAPI-5337; the PHP
 * counterpart of the TypeScript SDK's `wrapFetch`, FLT-1301).
 *
 * It owns only the Guzzle side — what a request and its outcome look like as
 * a call. Which scope the call belongs to, its start order, the clock and the
 * recording itself stay in the logger, behind the two closures it is built
 * with, so the middleware never sees logger state.
 *
 * Nothing here may throw into the caller's request on its own account: every
 * step that is about recording is guarded, and the request's own promise —
 * response or exception — reaches the caller unchanged.
 *
 * @internal Obtain one from `Logger::upstreamMiddleware()`.
 */
final class UpstreamMiddleware
{
    /**
     * cURL error numbers worth a readable `errorCode` — the network faults an
     * upstream call actually hits. Anything else is reported by the
     * exception's class name.
     */
    private const CURL_ERROR_CODES = [
        5 => 'CURLE_COULDNT_RESOLVE_PROXY',
        6 => 'CURLE_COULDNT_RESOLVE_HOST',
        7 => 'CURLE_COULDNT_CONNECT',
        28 => 'CURLE_OPERATION_TIMEDOUT',
        35 => 'CURLE_SSL_CONNECT_ERROR',
        47 => 'CURLE_TOO_MANY_REDIRECTS',
        52 => 'CURLE_GOT_NOTHING',
        55 => 'CURLE_SEND_ERROR',
        56 => 'CURLE_RECV_ERROR',
        60 => 'CURLE_PEER_FAILED_VERIFICATION',
    ];

    /**
     * @param list<string> $requestIdHeaders Checked in order; the first present wins.
     * @param \Closure(): array{0: RequestScope, 1: int, 2: float} $begin
     *        The scope, start-order sequence and start time of a call being sent.
     * @param \Closure(RequestScope, int, float, array<string, mixed>): void $record
     *        Stores a finished call; never throws.
     */
    public function __construct(
        private readonly string $name,
        private readonly array $requestIdHeaders,
        private readonly \Closure $begin,
        private readonly \Closure $record,
    ) {
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): PromiseInterface $handler
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            // The logger's own POSTs to ingest are never the caller's calls.
            if (!empty($options[Logger::INTERNAL_REQUEST_OPTION])) {
                return $handler($request, $options);
            }

            try {
                // Taken as the request is SENT, so the scope is the one the
                // caller is in now — not wherever an async promise settles.
                [$scope, $seq, $started] = ($this->begin)();
                $base = [
                    'name' => $this->name,
                    'method' => strtoupper($request->getMethod()),
                    'url' => (string) $request->getUri(),
                ];
                // Guzzle's `Middleware::retry()` passes its attempt counter
                // down in the request options. Present only when this
                // middleware sits inside the retry middleware; never guessed.
                $attempt = $options['retries'] ?? null;
            } catch (\Throwable) {
                // Recording is best-effort; the request is not.
                return $handler($request, $options);
            }

            try {
                $promise = $handler($request, $options);
            } catch (\Throwable $e) {
                // A handler that throws instead of rejecting: same treatment.
                $this->recordFailure($scope, $seq, $started, $base, $attempt, $request, $e);
                throw $e;
            }

            return $promise->then(
                function (mixed $response) use ($scope, $seq, $started, $base, $attempt): mixed {
                    if ($response instanceof ResponseInterface) {
                        $this->recordResponse($scope, $seq, $started, $base, $attempt, $response);
                    }

                    return $response;
                },
                function (mixed $reason) use ($scope, $seq, $started, $base, $attempt, $request): PromiseInterface {
                    if ($reason instanceof RequestException && $reason->getResponse() !== null) {
                        // An HTTP error status that `http_errors` (or another
                        // middleware below this one) turned into an
                        // exception: the upstream DID answer — record its
                        // status, as when `http_errors` is off.
                        $this->recordResponse($scope, $seq, $started, $base, $attempt, $reason->getResponse());
                    } else {
                        $this->recordFailure($scope, $seq, $started, $base, $attempt, $request, $reason);
                    }

                    // Rethrown unchanged — the caller's own exception.
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * @param array<string, mixed> $base
     */
    private function recordResponse(
        RequestScope $scope,
        int $seq,
        float $started,
        array $base,
        mixed $attempt,
        ResponseInterface $response,
    ): void {
        try {
            $call = $base;
            $call['status'] = $response->getStatusCode();
            foreach ($this->requestIdHeaders as $header) {
                $value = trim($response->getHeaderLine($header));
                if ($value !== '') {
                    $call['requestId'] = $value;
                    break;
                }
            }
            if ($attempt !== null) {
                $call['attempt'] = $attempt;
            }
            ($this->record)($scope, $seq, $started, $call);
        } catch (\Throwable) {
            // Recording must never cost the caller their response.
        }
    }

    /**
     * A call that got no HTTP response: no `status`, an `errorCode`, and the
     * transport's message.
     *
     * @param array<string, mixed> $base
     */
    private function recordFailure(
        RequestScope $scope,
        int $seq,
        float $started,
        array $base,
        mixed $attempt,
        RequestInterface $request,
        mixed $reason,
    ): void {
        try {
            $call = $base;
            $call['errorCode'] = self::errorCode($reason);
            $message = $reason instanceof \Throwable ? $reason->getMessage() : get_debug_type($reason);
            // Guzzle's transport messages end with the full URI ("… for
            // https://host/path?token=…"). The query string is stripped from
            // `url`; it must not ride along in `message` instead.
            $uri = (string) $request->getUri();
            if ($uri !== '') {
                $message = str_replace($uri, UpstreamTrail::stripUrl($uri), $message);
            }
            $call['message'] = $message;
            if ($attempt !== null) {
                $call['attempt'] = $attempt;
            }
            ($this->record)($scope, $seq, $started, $call);
        } catch (\Throwable) {
            // Never let recording replace the caller's own error.
        }
    }

    /**
     * `errorCode` for a transport failure: the cURL error's name where Guzzle
     * reports one (`CURLE_COULDNT_CONNECT`), else the exception's short class
     * name (`ConnectException`) — the PHP equivalents of the TypeScript SDK's
     * `ECONNREFUSED` / `AbortError`.
     */
    private static function errorCode(mixed $reason): string
    {
        if ($reason instanceof ConnectException || $reason instanceof RequestException) {
            $errno = $reason->getHandlerContext()['errno'] ?? null;
            if (is_int($errno) && isset(self::CURL_ERROR_CODES[$errno])) {
                return self::CURL_ERROR_CODES[$errno];
            }
        }
        if (is_object($reason)) {
            $class = get_class($reason);
            $slash = strrpos($class, '\\');

            return $slash === false ? $class : substr($class, $slash + 1);
        }

        return 'UnknownError';
    }
}

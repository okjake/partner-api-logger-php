<?php

/**
 * Router for `php -S`, used by capture-upstream-trail-wire.php (FLT-1301).
 *
 * Plays two parts at once: the ingest service — every `POST /logs` body is
 * appended, byte for byte, to the file named by CAPTURE_FILE — and the
 * upstream vendors the driver's Guzzle client calls through
 * `Logger::upstreamMiddleware()`.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/logs') {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    file_put_contents(getenv('CAPTURE_FILE'), json_encode([
        'headers' => [
            'content-type' => $headers['content-type'] ?? null,
            'x-api-key' => $headers['x-api-key'] ?? null,
            'x-tenant-token' => $headers['x-tenant-token'] ?? null,
        ],
        'raw' => file_get_contents('php://input'),
    ]) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"status":"success"}';
    return true;
}

if ($path === '/v1/customers') {
    header('Request-Id: req_8Hk2Lx9');
    header('Content-Type: application/json');
    echo '{"data":[]}';
    return true;
}

if ($path === '/v1/charges') {
    http_response_code(402);
    header('X-Request-Id: req_declined_1');
    header('Content-Type: application/json');
    echo '{"error":{"code":"card_declined"}}';
    return true;
}

http_response_code(404);
return true;

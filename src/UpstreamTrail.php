<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * The upstream-call trail (PAPI-5337; PHP parity FLT-1301).
 *
 * A request's outbound calls — to Stripe, a pricing service, an internal API —
 * are recorded into the request's scope and shipped as `upstream: [...]` on the
 * ONE line `logResponse()` emits (`message: "Outgoing response"`), then
 * cleared. No other line ever carries a trail.
 *
 * Ingest (`apps/ingest/src/upstream-trail.ts`, PAPI-5338) validates, sanitises
 * and caps the same shape server-side. The SDK applies the same caps first so
 * a well-behaved SDK never ships an over-cap line, and the field caps and URL
 * stripping mirror ingest's so what the SDK measures is what ingest keeps.
 * Every constant here is a copy of the TypeScript SDK's
 * (`packages/logger/src/upstream-trail.ts`), whose names are given beside each.
 *
 * The static helpers are public so callers and tests can reuse them; an
 * instance is one scope's accumulator and is internal to {@see Logger}.
 */
final class UpstreamTrail
{
    /** At most 20 calls on one response line (TS `UPSTREAM_TRAIL_MAX_CALLS`). */
    public const MAX_CALLS = 20;

    /**
     * At most 8 KB of serialised trail on one response line
     * (TS `UPSTREAM_TRAIL_MAX_BYTES`).
     */
    public const MAX_BYTES = 8192;

    /**
     * Response headers checked, in order, for the vendor's own request id
     * (TS `DEFAULT_UPSTREAM_REQUEST_ID_HEADERS`). The first one present wins.
     * `upstreamMiddleware()`'s `requestIdHeaders` option is checked BEFORE
     * these, so a caller can add a vendor-specific header without losing them.
     */
    public const DEFAULT_REQUEST_ID_HEADERS = [
        'request-id', // Stripe, Anthropic
        'x-request-id', // GitHub-style, most frameworks, OpenAI
        'x-amzn-requestid', // AWS API Gateway / Lambda
        'x-amz-request-id', // AWS S3
        'x-ms-request-id', // Azure
        'x-github-request-id', // GitHub
        'cf-ray', // anything behind Cloudflare — last, as it names the edge hop
    ];

    /**
     * Per-field caps, in characters (UTF-16 code units, as ingest counts them)
     * — ingest's `UPSTREAM_FIELD_MAX_CHARS`. Text fields are cut so the byte
     * cap is measured on roughly what ingest will keep; `method` over its cap
     * is a rejected call (a cut method is a wrong method), and `requestId`
     * over its cap, or outside printable ASCII, is omitted.
     */
    private const FIELD_MAX_CHARS = [
        'name' => 128,
        'method' => 16,
        'url' => 2048,
        'requestId' => 128,
        'errorCode' => 128,
        'message' => 1024,
    ];

    /**
     * An HTTP method: letters, plus `-` / `_` for extension methods
     * (`M-SEARCH`). Ingest rejects any other shape because `method` is the one
     * trail string it ships unsanitised.
     */
    private const METHOD_PATTERN = '/^[A-Za-z][A-Za-z_-]*$/';

    /** Largest `attempt` kept (ingest omits anything larger). */
    private const MAX_ATTEMPT = 1000;

    /** @var list<array{seq: int, call: array<string, mixed>}> */
    private array $entries = [];

    private int $dropped = 0;

    /**
     * Set when the scope that owns this trail has ENDED with no request above
     * it to hand its calls to: nothing is left to ship them on. Calls arriving
     * here are refused — the logger reports and drops them (ruling,
     * 2026-09-26: a call with no request line left is never parked elsewhere).
     */
    private bool $closed = false;

    /**
     * Strips the query string, fragment and userinfo from a URL-ish string
     * without parsing it — works on relative URLs and never re-encodes.
     * Byte-for-byte the algorithm ingest's `stripUpstreamUrl` runs; defence
     * in depth, so a token in a query string never leaves the process.
     */
    public static function stripUrl(string $url): string
    {
        $out = $url;
        $cut = strcspn($out, '?#');
        if ($cut < strlen($out)) {
            $out = substr($out, 0, $cut);
        }

        $schemeEnd = strpos($out, '://');
        if ($schemeEnd !== false) {
            $authorityStart = $schemeEnd + 3;
            $slash = strpos($out, '/', $authorityStart);
            $authorityEnd = $slash === false ? strlen($out) : $slash;
            $authority = substr($out, $authorityStart, $authorityEnd - $authorityStart);
            $at = strrpos($authority, '@');
            if ($at !== false) {
                $out = substr($out, 0, $authorityStart) . substr($out, $authorityStart + $at + 1);
            }
        }

        return $out;
    }

    /**
     * Validates one caller-supplied call and rebuilds it in the fixed key
     * order ingest emits, with field caps applied and the URL stripped.
     * Returns a reason string instead when ingest would reject the call
     * outright — the SDK drops it at the source rather than shipping a line
     * ingest would count as rejected. The reasons are the TypeScript SDK's,
     * word for word.
     *
     * Numbers follow JavaScript's rules, so a PHP caller gets the same answer
     * a TypeScript one does: `200.0` is the integer 200, `'200'` is not a
     * number at all.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|string
     */
    public static function normalise(array $raw): array|string
    {
        $name = self::nonEmptyString($raw['name'] ?? null);
        if ($name === null) {
            return '`name` must be a non-empty string';
        }
        $method = self::nonEmptyString($raw['method'] ?? null);
        if ($method === null) {
            return '`method` must be a non-empty string';
        }
        if (strlen($method) > self::FIELD_MAX_CHARS['method']) {
            return sprintf('`method` must be at most %d characters', self::FIELD_MAX_CHARS['method']);
        }
        if (preg_match(self::METHOD_PATTERN, $method) !== 1) {
            return '`method` must be an HTTP method (letters, `-`, `_`)';
        }
        $url = self::nonEmptyString($raw['url'] ?? null);
        if ($url === null) {
            return '`url` must be a non-empty string';
        }

        // Absent, null or 0 = no HTTP response (a network error): omitted.
        $status = null;
        $rawStatus = $raw['status'] ?? null;
        if ($rawStatus !== null && $rawStatus !== 0 && $rawStatus !== 0.0) {
            $status = self::integer($rawStatus);
            if ($status === null || $status < 100 || $status > 599) {
                return '`status` must be an HTTP status (100–599), or omitted for a network error';
            }
        }

        $durationMs = $raw['durationMs'] ?? null;
        if (
            !(is_int($durationMs) || is_float($durationMs))
            || !is_finite((float) $durationMs)
            || $durationMs < 0
        ) {
            return '`durationMs` must be a finite, non-negative number';
        }

        // Fixed key order — ingest's — so the same call always serialises the
        // same way; `durationMs` follows the optional `status`.
        $call = [
            'name' => self::cut($name, self::FIELD_MAX_CHARS['name']),
            'method' => $method,
            'url' => self::cut(self::stripUrl($url), self::FIELD_MAX_CHARS['url']),
        ];
        if ($status !== null) {
            $call['status'] = $status;
        }
        $call['durationMs'] = $durationMs;

        // Optional fields: an unusable value is omitted, never a reason to
        // lose the call.
        $requestId = self::nonEmptyString($raw['requestId'] ?? null);
        if (
            $requestId !== null
            && strlen($requestId) <= self::FIELD_MAX_CHARS['requestId']
            && preg_match('/^[\x21-\x7e]+$/', $requestId) === 1
        ) {
            $call['requestId'] = $requestId;
        }
        $errorCode = self::nonEmptyString($raw['errorCode'] ?? null);
        if ($errorCode !== null) {
            $call['errorCode'] = self::cut($errorCode, self::FIELD_MAX_CHARS['errorCode']);
        }
        $message = $raw['message'] ?? null;
        if (is_string($message) && $message !== '') {
            $call['message'] = self::cut(self::validUtf8($message), self::FIELD_MAX_CHARS['message']);
        }
        $attempt = self::integer($raw['attempt'] ?? null);
        if ($attempt !== null && $attempt >= 0 && $attempt <= self::MAX_ATTEMPT) {
            $call['attempt'] = $attempt;
        }

        return $call;
    }

    /** Whether a call recorded here now would be refused. */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Records a normalised call; `false` (and nothing stored) if the trail is
     * closed.
     *
     * Memory is bounded as calls arrive: the count cap is enforced on insert,
     * so a request that makes ten thousand calls without a response holds 20.
     *
     * @param array<string, mixed> $call
     */
    public function record(int $seq, array $call): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->entries[] = ['seq' => $seq, 'call' => $call];
        if (count($this->entries) > self::MAX_CALLS) {
            // Drop the OLDEST call — the lowest seq, which is not necessarily
            // the first inserted when async Guzzle calls finish out of order.
            $oldest = 0;
            foreach ($this->entries as $i => $entry) {
                if ($entry['seq'] < $this->entries[$oldest]['seq']) {
                    $oldest = $i;
                }
            }
            array_splice($this->entries, $oldest, 1);
            $this->dropped++;
        }

        return true;
    }

    /**
     * Refuses every later call and returns how many calls it was still
     * holding (kept + already cap-dropped) — the ones that will never ship.
     */
    public function close(): int
    {
        $lost = count($this->entries) + $this->dropped;
        $this->entries = [];
        $this->dropped = 0;
        $this->closed = true;

        return $lost;
    }

    /**
     * Moves every unshipped call — and the count of calls this trail already
     * dropped — onto `$to` (a nested scope's calls join the enclosing
     * request's trail). Calls keep their start-order `seq`, and `$to` applies
     * its own count cap as they arrive, so every drop on either side lands in
     * `$to`'s `_upstreamDropped`.
     *
     * Returns how many calls (kept + already dropped) could NOT move because
     * `$to` is closed — the logger reports and drops them.
     */
    public function handOff(UpstreamTrail $to): int
    {
        if ($to === $this) {
            return 0;
        }

        $held = count($this->entries) + $this->dropped;
        $lost = 0;
        if ($to->closed) {
            $lost = $held;
        } else {
            foreach ($this->entries as $entry) {
                $to->record($entry['seq'], $entry['call']);
            }
            $to->dropped += $this->dropped;
        }
        $this->entries = [];
        $this->dropped = 0;

        return $lost;
    }

    /**
     * Hands over everything recorded and leaves the trail empty: the kept
     * calls in call order, and how many the caps removed. `null` when nothing
     * was recorded, so a response with no calls carries no `upstream` key at
     * all — its line is byte-identical to one from an SDK without the trail.
     *
     * The byte cap measures the trail exactly as it will sit inside the line:
     * `json_encode` with the line's own flags ({@see Logger::LINE_JSON_FLAGS}).
     * PHP escapes non-ASCII as `\uXXXX` where ingest re-measures raw UTF-8, so
     * this count is never below ingest's: a PHP trail can never trip ingest's
     * cap, at the price of occasionally dropping one call more than ingest
     * would for a trail full of non-ASCII text.
     *
     * @return array{calls: list<array<string, mixed>>, dropped: int}|null
     */
    public function take(): ?array
    {
        if ($this->entries === [] && $this->dropped === 0) {
            return null;
        }

        $entries = $this->entries;
        usort($entries, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        $calls = array_map(static fn (array $entry): array => $entry['call'], $entries);
        $dropped = $this->dropped;
        $this->entries = [];
        $this->dropped = 0;

        while ($calls !== [] && self::byteLength($calls) > self::MAX_BYTES) {
            array_shift($calls);
            $dropped++;
        }

        return ['calls' => $calls, 'dropped' => $dropped];
    }

    /**
     * UTF-8 bytes of the trail as the line serialises it. An unencodable
     * trail (normalise() rules that out) counts as over any cap, so the
     * caller sheds calls rather than shipping a line `json_encode` will
     * refuse.
     *
     * @param list<array<string, mixed>> $calls
     */
    public static function byteLength(array $calls): int
    {
        $json = json_encode($calls, Logger::LINE_JSON_FLAGS);

        return $json === false ? PHP_INT_MAX : strlen($json);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim(self::validUtf8($value));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * An integer by JavaScript's `Number.isInteger`: an int, or a float with
     * no fractional part. Anything else — a numeric string included — is not.
     */
    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Replaces invalid UTF-8 with U+FFFD. One bad byte in a trail field would
     * otherwise make `json_encode` refuse the WHOLE response line, losing the
     * response along with the call. `htmlspecialchars` is core PHP, so this
     * needs no ext-mbstring.
     */
    private static function validUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        return htmlspecialchars_decode(
            htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ENT_NOQUOTES,
        );
    }

    /**
     * The first `$max` characters, counted the way ingest (JavaScript) counts
     * them: UTF-16 code units, so a character outside the BMP counts twice
     * and is never split.
     */
    private static function cut(string $value, int $max): string
    {
        $value = self::validUtf8($value);
        // Fast path: no more bytes than the cap means no more code units.
        if (strlen($value) <= $max) {
            return $value;
        }

        // At most $max code points can fit; look no further than that.
        preg_match('/^.{0,' . $max . '}/su', $value, $head);
        $out = '';
        $units = 0;
        foreach (preg_split('//u', $head[0], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $width = strlen($char) === 4 ? 2 : 1;
            if ($units + $width > $max) {
                break;
            }
            $out .= $char;
            $units += $width;
        }

        return $out;
    }
}

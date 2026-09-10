<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * Public PII redaction helper for `partner-api/logger`.
 *
 * Mirrors the TypeScript `redactPII` exported from `@partner-api/logger`.
 * Strips emails, JWTs, Bearer tokens, named API-key prefixes, password
 * assignments, phone numbers, credit cards, IPv4/IPv6 addresses, URL query
 * strings, and long secret-shaped runs from logger input. Recurses into
 * arrays/associative arrays; sensitive keys (e.g. `password`, `api_key`,
 * `secret`, `cookie`) have their values replaced regardless of shape.
 *
 * Implemented inline (no external runtime dependency) so the public SDK
 * stays lightweight. The ruleset is kept in sync with the TypeScript
 * implementation — when one moves, the other should follow. See
 * PAPI-1098 + PAPI-1102.
 */
final class RedactPii
{
    /** Whole segments (or exact +s plurals), at any position. */
    private const SENSITIVE_SEGMENTS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'apikey',
        'accesstoken',
        'refreshtoken',
        'privatekey',
        'publickey',
        'certificate',
        'ssn',
        'creditcard',
        'cvv',
        'cvc',
        'bearer',
        'sessionid',
    ];

    /** Contiguous runs; only the final word is prefix-tolerant. */
    private const SENSITIVE_SEGMENT_RUNS = [
        ['api', 'key'],
        ['access', 'token'],
        ['refresh', 'token'],
        ['private', 'key'],
        ['public', 'key'],
        ['session', 'key'],
        ['session', 'id'],
        ['social', 'security'],
        ['credit', 'card'],
        ['x', 'auth', 'token'],
    ];

    /** Ambiguous terms (or exact +s plurals), only as the last segment. */
    private const SENSITIVE_HEAD_NOUNS = [
        'token',
        'session',
        'cookie',
        'cert',
        'pin',
    ];

    /**
     * @var array<string, bool>
     */
    private const DEFAULTS = [
        'preserveStructure' => true,
        'redactEmails' => true,
        'redactApiKeys' => true,
        'redactTokens' => true,
        'redactPasswords' => true,
        'redactPhoneNumbers' => true,
        'redactCreditCards' => true,
        'redactIpAddresses' => true,
        'redactUrls' => true,
        'redactUuids' => false,
    ];

    /**
     * Redact PII from a string or array (associative or list).
     *
     * - Strings: run the regex ruleset.
     * - Arrays: recurse; sensitive keys get their value replaced with a
     *   category-specific placeholder.
     * - Scalars (int/float/bool) and null: returned as-is.
     *
     * @param string|array<mixed>|int|float|bool|null $input
     * @param array<string, bool> $options
     * @return string|array<mixed>|int|float|bool|null
     */
    public static function redact(mixed $input, array $options = []): mixed
    {
        $opts = array_merge(self::DEFAULTS, $options);
        return self::redactValue($input, $opts);
    }

    /**
     * @param array<string, bool> $options
     */
    private static function redactValue(mixed $value, array $options): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return self::redactString($value, $options);
        }

        if (is_array($value)) {
            return self::redactArray($value, $options);
        }

        return $value;
    }

    /**
     * @param array<mixed> $arr
     * @param array<string, bool> $options
     * @return array<mixed>
     */
    private static function redactArray(array $arr, array $options): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                if ($options['preserveStructure']) {
                    $result[$key] = self::getRedactedValueForKey($key);
                }
                // else: drop the key entirely
            } else {
                $result[$key] = self::redactValue($value, $options);
            }
        }
        return $result;
    }

    /**
     * Canonical policy: packages/database/src/pii-sanitizer.ts (PAPI-4367).
     * Match whole segments, prefix-tolerant multi-word runs, or head nouns.
     * Single terms also match an exact trailing s, never an arbitrary prefix.
     */
    private static function isSensitiveKey(string $key): bool
    {
        $segments = self::toKeySegments($key);
        if ($segments === []) {
            return false;
        }
        if (self::namesTerm($segments[count($segments) - 1], self::SENSITIVE_HEAD_NOUNS)) {
            return true;
        }
        foreach ($segments as $segment) {
            if (self::namesTerm($segment, self::SENSITIVE_SEGMENTS)) {
                return true;
            }
        }
        foreach (self::SENSITIVE_SEGMENT_RUNS as $run) {
            if (self::hasSegmentRun($segments, $run)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function toKeySegments(string $key): array
    {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        // Fixed-width lookahead keeps long acronym runs linear in PCRE too.
        $key = preg_replace('/([A-Z])(?=[A-Z][a-z])/', '$1_', $key) ?? $key;
        return preg_split('/[^a-z0-9]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $terms */
    private static function namesTerm(string $segment, array $terms): bool
    {
        return in_array($segment, $terms, true)
            || (str_ends_with($segment, 's') && in_array(substr($segment, 0, -1), $terms, true));
    }

    /**
     * @param list<string> $segments
     * @param list<string> $run
     */
    private static function hasSegmentRun(array $segments, array $run): bool
    {
        $lastOffset = count($run) - 1;
        $segmentCount = count($segments);
        for ($start = 0; $start + $lastOffset < $segmentCount; $start++) {
            $matched = true;
            foreach ($run as $offset => $term) {
                if ($offset === $lastOffset
                    ? !str_starts_with($segments[$start + $offset], $term)
                    : $segments[$start + $offset] !== $term
                ) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return true;
            }
        }
        return false;
    }

    private static function getRedactedValueForKey(string $key): string
    {
        $lower = strtolower($key);
        if (str_contains($lower, 'email')) return '[EMAIL_REDACTED]';
        if (str_contains($lower, 'password') || str_contains($lower, 'pwd')) return '[PASSWORD_REDACTED]';
        if (str_contains($lower, 'token')) return '[TOKEN_REDACTED]';
        if (str_contains($lower, 'key')) return '[KEY_REDACTED]';
        if (str_contains($lower, 'secret')) return '[SECRET_REDACTED]';
        if (str_contains($lower, 'phone')) return '[PHONE_REDACTED]';
        if (str_contains($lower, 'card') || str_contains($lower, 'cvv') || str_contains($lower, 'cvc')) return '[CARD_REDACTED]';
        if (str_contains($lower, 'session')) return '[SESSION_REDACTED]';
        if (str_contains($lower, 'cookie')) return '[COOKIE_REDACTED]';
        return '[SENSITIVE_DATA_REDACTED]';
    }

    /**
     * @param array<string, bool> $options
     */
    private static function redactString(string $str, array $options): string
    {
        $sanitized = $str;

        if ($options['redactTokens']) {
            $sanitized = preg_replace(
                '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/',
                '[JWT_TOKEN_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
            // Bearer tokens may include dots (JWT-shaped `abc.def.ghi`, opaque
            // dotted tokens) — include `.` in the character class so the whole
            // credential is consumed, not just the first segment.
            $sanitized = preg_replace(
                '/\bBearer\s+[A-Za-z0-9._-]+/i',
                'Bearer [TOKEN_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
            $sanitized = preg_replace(
                '/\b(?:access_token|refresh_token)["\s:=]+[A-Za-z0-9_-]+/i',
                'access_token=[TOKEN_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactApiKeys']) {
            $sanitized = preg_replace(
                '/\b(?:sk-|pk_|rk_|ak_|key_|token_)[A-Za-z0-9_-]{10,}\b/',
                '[API_KEY_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
            $sanitized = preg_replace(
                '/\b[A-Za-z0-9]{32,}\b/',
                '[POTENTIAL_KEY_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
            $sanitized = preg_replace(
                '/\b[A-Za-z0-9+\/]{40,}={0,2}\b/',
                '[ENCODED_KEY_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactEmails']) {
            $sanitized = preg_replace(
                "/\b[A-Za-z0-9.!#\$%&'*+\\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\b/",
                '[EMAIL_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactPasswords']) {
            $sanitized = preg_replace(
                '/"password"\s*:\s*"[^"]*"/i',
                '"password": "[PASSWORD_REDACTED]"',
                $sanitized,
            ) ?? $sanitized;
            $sanitized = preg_replace(
                '/(?:password|pwd)[=:][^&\s]*/i',
                'password=[PASSWORD_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactPhoneNumbers']) {
            $usPhonePattern = '/(?:\+?1[-.\s]?)?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}\b/';
            // Walk matches in order so each replacement sees its actual offset
            // in the original-up-to-now string. Using preg_replace_callback
            // without offset means we'd have to re-find the match, which
            // misbehaves when the same phone shape appears twice.
            $matches = [];
            preg_match_all(
                $usPhonePattern,
                $sanitized,
                $matches,
                PREG_OFFSET_CAPTURE,
            );
            if (!empty($matches[0])) {
                $offsetShift = 0;
                foreach ($matches[0] as [$match, $origOffset]) {
                    $offset = (int) $origOffset + $offsetShift;
                    $normalized = trim($match);
                    $hasFormatting =
                        preg_match('/[()\s.-]/', $normalized) === 1
                        || str_starts_with($normalized, '+');
                    $replacement = $match;
                    if ($hasFormatting) {
                        $replacement = '[PHONE_REDACTED]';
                    } else {
                        $contextRadius = 15;
                        $start = max(0, $offset - $contextRadius);
                        $end = min(strlen($sanitized), $offset + strlen($match) + $contextRadius);
                        $context = strtolower(substr($sanitized, $start, $end - $start));
                        foreach (['phone', 'mobile', 'cell', 'contact', 'tel', 'call'] as $kw) {
                            if (str_contains($context, $kw)) {
                                $replacement = '[PHONE_REDACTED]';
                                break;
                            }
                        }
                    }
                    if ($replacement !== $match) {
                        $sanitized = substr($sanitized, 0, $offset)
                            . $replacement
                            . substr($sanitized, $offset + strlen($match));
                        $offsetShift += strlen($replacement) - strlen($match);
                    }
                }
            }
            $sanitized = preg_replace(
                '/\+[1-9]\d{1,14}\b/',
                '[PHONE_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactCreditCards']) {
            $sanitized = preg_replace(
                '/\b(?:\d{4}[-\s]?){3}\d{4}\b/',
                '[CARD_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
            $sanitized = preg_replace(
                '/\bcvv\s*[=:]\s*\d{3,4}\b/i',
                'cvv=[CVV_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactIpAddresses']) {
            $sanitized = preg_replace(
                '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/',
                '[IP_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
            $sanitized = preg_replace(
                '/\b(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}\b/',
                '[IPV6_REDACTED]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactUuids']) {
            $sanitized = preg_replace(
                '/\b[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\b/i',
                '[ID]',
                $sanitized,
            ) ?? $sanitized;
        }

        if ($options['redactUrls']) {
            $sanitized = preg_replace_callback(
                '/https?:\/\/[^\s]+/',
                static function (array $m): string {
                    $url = $m[0];
                    $parts = parse_url($url);
                    if ($parts !== false && isset($parts['query'])) {
                        $scheme = $parts['scheme'] ?? 'https';
                        $host = $parts['host'] ?? '';
                        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                        $path = $parts['path'] ?? '';
                        return $scheme . '://' . $host . $port . $path . '?[QUERY_REDACTED]';
                    }
                    return $url;
                },
                $sanitized,
            ) ?? $sanitized;
        }

        return $sanitized;
    }
}

// The procedural `redactPII()` alias lives in `src/functions.php`, which is
// registered via `composer.json` → `autoload.files` so it is loaded eagerly.
// PHP does not autoload functions, so PSR-4 alone would leave the documented
// `use function PartnerApi\Logger\redactPII;` form undefined until the class
// happened to be loaded for some other reason.

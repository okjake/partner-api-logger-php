<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * Public PII redaction helper for `partner-api/logger`.
 *
 * Mirrors the TypeScript `redactPII` exported from `@partner-api/logger`
 * (`packages/logger/src/redact-pii.ts`), rule by rule and in the same order —
 * the order decides which marker a span ends up under. Strips emails, phone
 * numbers, credit-card numbers, JWTs, Bearer tokens, issuer-prefixed
 * credentials, password assignments, IPv4/IPv6 addresses, URL query strings and
 * long base64/hex secret-shaped runs. Recurses into arrays; sensitive keys (e.g.
 * `password`, `api_key`, `secret`, `cookie`) have their values replaced
 * regardless of shape, and a KEY that is itself PII — a phone number, an email
 * address, a credential — is redacted in place (PAPI-4960).
 *
 * "Mirrors" is enforced, not hoped for (FLT-1191): `tests/RedactPiiCorpusTest.php`
 * runs `packages/database/src/pii-redaction-corpus.json`, the contract the
 * platform sanitiser and the JavaScript SDK already meet (PAPI-4859), and holds
 * {@see self::SECRET_SHAPE_SOURCES} to the corpus's credential-prefix list.
 * Change a rule in all three places, in the same PR.
 *
 * Porting notes, for whoever changes a rule next:
 *
 * - Every pattern runs WITHOUT the `u` modifier, on bytes. Under `u` PHP turns
 *   on Unicode `\b`/`\d`/`\w`, which the JavaScript rules do not have, and an
 *   input that is not valid UTF-8 would make every rule fail. So `\b` is
 *   written as an explicit ASCII lookaround, `\d` as `[0-9]`, and JavaScript's
 *   `\s` — which does take the Unicode spaces — as {@see self::SPACE}.
 * - The rules the TypeScript implements as scans (emails, JWTs, phones,
 *   encoded runs) are scans here too: as backtracking regexes they are
 *   quadratic, and PCRE gives up on them at its backtrack limit.
 * - No rule fails open. Any PCRE error replaces the whole string with
 *   {@see self::REDACTION_FAILED} rather than returning it partly redacted or,
 *   as `preg_replace(...) ?? $text` used to, not redacted at all.
 *
 * Implemented inline (no external runtime dependency, no PHP extension beyond
 * core) so the public SDK stays lightweight. See PAPI-1098 + PAPI-1102.
 */
final class RedactPii
{
    /**
     * What a string (or an object key) becomes when a rule cannot run on it —
     * a PCRE error such as the backtrack or JIT stack limit. The string is
     * dropped whole: a partly redacted string may still hold what the failed
     * rule was there to remove.
     */
    public const REDACTION_FAILED = '[REDACTION_FAILED]';

    /**
     * The platform's `SECRET_SHAPES` sources (packages/database/src/
     * secret-shapes.ts): issuer-prefixed credentials. Public for the corpus
     * test, which holds it to the corpus's `secretShapeSources`; not part of
     * the SDK surface.
     *
     * @internal
     * @var list<string>
     */
    public const SECRET_SHAPE_SOURCES = [
        'gh[pousr]_[A-Za-z0-9]{20,}',
        'github_pat_[A-Za-z0-9_]{20,}',
        'A(?:KI|SI)A[A-Z2-7]{16}',
        'xox[abprs]-[A-Za-z0-9-]{10,}',
        '(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}',
        'whsec_[A-Za-z0-9]{20,}',
        'sk-(?:proj|svcacct|admin|ant)-[A-Za-z0-9_-]{20,}',
        'sk-[A-Za-z0-9]{20,}',
        'AIza[0-9A-Za-z_-]{20,}',
        'npm_[A-Za-z0-9]{20,}',
        'glpat-[A-Za-z0-9_-]{16,}',
        '(?:tenant_live_|papi_admin_)[A-Za-z0-9_-]{8,}',
        'psa_[A-Za-z0-9_-]{43}',
    ];

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

    // ── Pattern pieces ────────────────────────────────────────────────────

    /** JavaScript's `\b` just before a word character. */
    private const WORD_START = '(?<![A-Za-z0-9_])';

    /** JavaScript's `\b` just after a word character. */
    private const WORD_END = '(?![A-Za-z0-9_])';

    /** JavaScript's `\b` where either side may be a word character. */
    private const BOUNDARY = '(?:(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])|(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]))';

    /**
     * JavaScript's `\s`, in UTF-8: the ASCII spaces, NBSP, U+1680,
     * U+2000-U+200A, U+2028, U+2029, U+202F, U+205F, U+3000 and U+FEFF.
     */
    private const SPACE = '(?:[\t\n\x0B\f\r ]|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)';

    /**
     * JavaScript's `[^\s]+`, in UTF-8: a run of bytes none of which starts a
     * {@see self::SPACE}. The bytes that cannot start one go by the possessive
     * run, so the group repeats once per lead byte rather than once per byte:
     * without the JIT a per-byte group hit PCRE's match limit about a
     * megabyte into a match, and the whole string became
     * {@see self::REDACTION_FAILED}.
     */
    private const NOT_SPACE_RUN = '(?:[^\t\n\x0B\f\r \xC2\xE1\xE2\xE3\xEF]++|\xC2(?!\xA0)|\xE1(?!\x9A\x80)|\xE2(?!\x80[\x80-\x8A\xA8\xA9\xAF]|\x81\x9F)|\xE3(?!\x80\x80)|\xEF(?!\xBB\xBF))++';

    /** JavaScript's `[^&\s]*`, built as {@see self::NOT_SPACE_RUN} is. */
    private const NOT_AMPERSAND_OR_SPACE_RUN = '(?:[^&\t\n\x0B\f\r \xC2\xE1\xE2\xE3\xEF]++|\xC2(?!\xA0)|\xE1(?!\x9A\x80)|\xE2(?!\x80[\x80-\x8A\xA8\xA9\xAF]|\x81\x9F)|\xE3(?!\x80\x80)|\xEF(?!\xBB\xBF))*+';

    /**
     * Leads every case-insensitive pattern run over a whole string. Without
     * the JIT (`pcre.jit=0`, or a host that cannot allocate executable
     * memory), PCRE 10.39's interpreter looks for a caseless first or required
     * character by searching for each case separately, and when one case never
     * occurs it searches to the end of the subject again on every match
     * attempt: `Bearer ` repeated to 1 MB took 1.2 s against 20 ms at 125 KB.
     * Turning the start-up optimisations off costs a few milliseconds per
     * megabyte and keeps the rule linear either way.
     */
    private const CASELESS_START = '(*NO_START_OPT)';

    /** Mirrors `AFTER_JSON_ESCAPE`: just after an escaped `\n`, `\t` or `\uXXXX`. */
    private const AFTER_JSON_ESCAPE = '(?<=\\\\[nrtbf]|\\\\u[0-9A-Fa-f]{4})';

    /** Mirrors `JSON_ESCAPE_PREFIX`: the escape a rule's second pattern captures. */
    private const JSON_ESCAPE_PREFIX = '(\\\\(?:[nrtbf]|u[0-9A-Fa-f]{4}))';

    // ── Rule bodies (the part after a leading `\b`) ───────────────────────

    private const BEARER_BODY = 'Bearer' . self::SPACE . '++[A-Za-z0-9._-]++';
    private const OAUTH_TOKEN_ASSIGNMENT_BODY = '(?:access_token|refresh_token)(?:["\:=]|' . self::SPACE . ')++[A-Za-z0-9_-]++';
    private const PREFIXED_KEY_BODY = '(?:sk-|pk_|rk_|ak_|key_|token_)[A-Za-z0-9_-]{10,}' . self::BOUNDARY;
    private const CARD_BODY = '(?:[0-9]{4}(?:-|' . self::SPACE . ')?){3}[0-9]{4}' . self::WORD_END;
    private const CVV_BODY = 'cvv' . self::SPACE . '*+[=:]' . self::SPACE . '*+[0-9]{3,4}' . self::WORD_END;
    private const IPV4_BODY = '(?:[0-9]{1,3}\.){3}[0-9]{1,3}' . self::WORD_END;
    private const IPV6_BODY = '(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}' . self::WORD_END;

    private const LONG_TOKEN = '~' . self::WORD_START . '[A-Za-z0-9]{32,}+' . self::WORD_END . '~';
    private const ENCODED_KEY = '~' . self::BOUNDARY . '[A-Za-z0-9+/]{40,}={0,2}' . self::BOUNDARY . '~';
    private const PASSWORD_JSON = '~' . self::CASELESS_START . '"password"' . self::SPACE . '*+:' . self::SPACE . '*+"[^"]*+"~i';
    private const PASSWORD_ASSIGNMENT = '~' . self::CASELESS_START . '(?:password|pwd)[=:]' . self::NOT_AMPERSAND_OR_SPACE_RUN . '~i';
    private const UUID = '~' . self::CASELESS_START . '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}~i';
    private const URL_WITH_QUERY = '~https?://' . self::NOT_SPACE_RUN . '~';

    /**
     * Mirrors `URL_PATH_WORD`, `URL_PATH_LONG_WORD`, `URL_PATH_CONSONANTS`,
     * `URL_PATH_CLUSTER` and `URL_PATH_NAME_WORD` (FLT-1107): the shapes a
     * `/`-separated piece of an encoded run must have to stay as a REST path
     * word. See {@see self::isUrlPathWord()}.
     */
    private const URL_PATH_WORD = '~\A(?:(?:[a-z]+|[A-Z][a-z]+)(?:[A-Z][a-z]+)*[0-9]{0,2}|[A-Z]{1,4}[0-9]{0,2}|[a-z][0-9]{1,2}|[0-9]+)\z~';
    /*
     * `URL_PATH_LONG_WORD`'s hump groups are atomic here. In the TypeScript a
     * hump's `[a-z]*[aeiouy][a-z]*` can split its letters many ways, and when a
     * later hump fails the engine retries every split of every earlier one:
     * `AaAa…AaXbc` (31 characters) costs millions of steps. Every successful
     * match takes each hump's letters to the next capital, digit or end
     * anyway, so committing to the first split that does that matches exactly
     * the same pieces.
     */
    private const URL_PATH_LONG_WORD = '~\A(?:[a-z]+|[A-Z](?>[a-z]*[aeiouy][a-z]*|[a-z]))(?:[A-Z](?>[a-z]*[aeiouy][a-z]*|[a-z]))*[0-9]{0,2}\z~';
    private const URL_PATH_CONSONANTS = '~[^aeiouy0-9]{4}~i';
    private const URL_PATH_CLUSTER = '~ch|ck|gh|ng|ph|sh|th|wh|scr|spl|spr|str|ql~i';
    private const URL_PATH_NAME_WORD = '~\A[A-Za-z][a-z]{2,}\z~';

    /** A `.` or `..` path segment, in any of the spellings WHATWG resolves. */
    private const DOT_SEGMENT = '~' . self::CASELESS_START . '(?:\A|[/\\\\])(?:\.|%2e){1,2}(?:[/\\\\]|\z)~i';

    // ── Scans ─────────────────────────────────────────────────────────────

    private const EMAIL_REDACTION = '[EMAIL_REDACTED]';
    private const PHONE_REDACTION = '[PHONE_REDACTED]';
    private const JWT_REDACTION = '[JWT_TOKEN_REDACTED]';
    private const ENCODED_KEY_REDACTION = '[ENCODED_KEY_REDACTED]';

    private const JWT_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
    private const WORD_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';
    private const DIGITS = '0123456789';
    private const HEX_DIGITS = '0123456789abcdefABCDEF';
    private const NON_ASCII_BYTES = "\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8A\x8B\x8C\x8D\x8E\x8F\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9A\x9B\x9C\x9D\x9E\x9F\xA0\xA1\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xAB\xAC\xAD\xAE\xAF\xB0\xB1\xB2\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xBB\xBC\xBD\xBE\xBF\xC0\xC1\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA\xCB\xCC\xCD\xCE\xCF\xD0\xD1\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xDB\xDC\xDD\xDE\xDF\xE0\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA\xFB\xFC\xFD\xFE\xFF";
    /** Mirrors `PLATFORM_LOCAL_CHAR`: what an address's local part is made of. */
    private const EMAIL_LOCAL_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.!#$%&'*+/=?^_`{|}~-";
    /** Mirrors `JSON_ESCAPE_BEHIND`. */
    private const JSON_ESCAPE_BEHIND = '~\\\\(?:[nrtbf]|u[0-9A-Fa-f]{4})\z~';

    /** Mirrors `NBSP_ENTITY` (the fold reads at most ten characters of it). */
    private const NBSP_ENTITY = '~\G&(?:nbsp|#0{0,8}160|#x0{0,8}a0);~i';

    private const E164_MAX_DIGITS = 15;
    private const COMPLETE_PHONE_DIGITS = 11;
    private const FINAL_GROUP_MAX_DIGITS = 2;
    private const STATUS_CODE_AFTER_DIGITS = 10;
    private const MARKER_DIGITS = 2;
    private const TAIL_SEPARATORS = " \t./()-";
    private const TOKEN_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const NOT_A_PHONE_GROUP = '~\G(?:[0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{1,2}:[0-9]{2}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-)~';
    private const UNIT_AFTER_GROUP = '~\G[ \t]?(?:ms|secs?|s|bytes|kb|mb|gb|kib|mib|KB|MB|KiB|MiB)(?![0-9A-Za-z])~';
    private const PHONE_MARKER = '~\[PHONE_REDACTED\]~';
    private const PHONE = '~(?:\+?1(?:[-.]|' . self::SPACE . ')?)?\(?[0-9]{3}\)?(?:[-.]|' . self::SPACE . ')?[0-9]{3}(?:[-.]|' . self::SPACE . ')?[0-9]{4}' . self::WORD_END . '|\+[1-9][0-9]{0,14}(?![0-9A-Za-z])~';
    private const PHONE_FORMATTING = '~[().-]|' . self::SPACE . '~';
    private const PHONE_CONTEXT_WORDS = ['phone', 'mobile', 'cell', 'contact', 'tel', 'call'];
    private const PHONE_CONTEXT_RADIUS = 15;
    private const INTERNATIONAL_NUMBER = '~\A\+[1-9][0-9]{0,14}\z~';
    private const MIN_INTERNATIONAL_DIGITS = 7;

    /**
     * Redact PII from a string or array (associative or list).
     *
     * - Strings: run the regex ruleset.
     * - Lists: recurse into each item.
     * - Associative arrays: recurse; a sensitive key gets its value replaced
     *   with a category-specific placeholder, and a key that is itself PII is
     *   redacted (`#2`, `#3`… when two keys redact alike).
     * - Scalars (int/float/bool), null and objects: returned as-is.
     *
     * A string a rule cannot run on (a PCRE error) comes back as
     * {@see self::REDACTION_FAILED}, never unredacted.
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
            return array_is_list($value)
                ? array_map(static fn (mixed $item): mixed => self::redactValue($item, $options), $value)
                : self::redactObject($value, $options);
        }

        return $value;
    }

    /**
     * Mirrors `redactObject`. The ORIGINAL name decides whether the value is a
     * secret: a name already replaced by a marker no longer says what it named.
     * `__proto__` is dropped, as the TypeScript drops it (PAPI-4797), so both
     * implementations log the same object.
     *
     * @param array<mixed> $object
     * @param array<string, bool> $options
     * @return array<mixed>
     */
    private static function redactObject(array $object, array $options): array
    {
        $result = [];
        foreach ($object as $key => $value) {
            $key = (string) $key;
            if (self::isSensitiveKeyOrUnreadable($key)) {
                if (!$options['preserveStructure']) {
                    continue;
                }
                $value = self::getRedactedValueForKey($key);
            } else {
                $value = self::redactValue($value, $options);
            }
            $name = self::redactFieldName($key, $options);
            if ($name === '__proto__') {
                continue;
            }
            $result[self::unusedFieldName($name, $result)] = $value;
        }
        return $result;
    }

    /**
     * Mirrors `unusedFieldName`: two names that redact to one string keep both
     * values, the second under a `#2` suffix. Dropping one would be data loss
     * the reader cannot see.
     *
     * @param array<mixed> $siblings
     */
    private static function unusedFieldName(string $name, array $siblings): string
    {
        if (!array_key_exists($name, $siblings)) {
            return $name;
        }
        $suffix = 2;
        while (array_key_exists("{$name}#{$suffix}", $siblings)) {
            $suffix++;
        }
        return "{$name}#{$suffix}";
    }

    /** A key whose segments cannot be read (a PCRE error) is treated as sensitive. */
    private static function isSensitiveKeyOrUnreadable(string $key): bool
    {
        try {
            return self::isSensitiveKey($key);
        } catch (LoggerException) {
            return true;
        }
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
        $key = self::pcre(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
        // Fixed-width lookahead keeps long acronym runs linear in PCRE too.
        $key = self::pcre(preg_replace('/([A-Z])(?=[A-Z][a-z])/', '$1_', $key));
        return self::pcre(preg_split('/[^a-z0-9]+/', self::lowercaseKeyForAsciiMatching($key), -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function lowercaseKeyForAsciiMatching(string $key): string
    {
        // Canonical Node 22 / Unicode 16 lowercase introduces ASCII from only
        // these two non-ASCII scalars. Other non-ASCII characters stay separators.
        // Fold AFTER case-boundary splitting, without compatibility normalization.
        $key = strtr($key, ["\u{0130}" => "i\u{0307}", "\u{212A}" => 'k']);
        // Explicit ASCII mapping also stays locale-independent on PHP 8.1.
        return strtr($key, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
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
        $lower = self::lowercaseKeyForAsciiMatching($key);
        if (str_contains($lower, 'email')) return '[EMAIL_REDACTED]';
        if (str_contains($lower, 'password') || str_contains($lower, 'pwd')) return '[PASSWORD_REDACTED]';
        if (str_contains($lower, 'token')) return '[TOKEN_REDACTED]';
        if (str_contains($lower, 'key')) return '[KEY_REDACTED]';
        if (str_contains($lower, 'secret')) return '[SECRET_REDACTED]';
        if (str_contains($lower, 'phone')) return '[PHONE_REDACTED]';
        if (str_contains($lower, 'card') || str_contains($lower, 'cvv')) return '[CARD_REDACTED]';
        if (str_contains($lower, 'session')) return '[SESSION_REDACTED]';
        if (str_contains($lower, 'cookie')) return '[COOKIE_REDACTED]';
        return '[SENSITIVE_DATA_REDACTED]';
    }

    // ── Strings and names ─────────────────────────────────────────────────

    /**
     * @param array<string, bool> $options
     */
    private static function redactString(string $str, array $options): string
    {
        try {
            return self::applyStringRules($str, $options);
        } catch (LoggerException) {
            return self::REDACTION_FAILED;
        }
    }

    /**
     * @param array<string, bool> $options
     */
    private static function applyStringRules(string $str, array $options): string
    {
        $sanitized = $str;

        if ($options['redactTokens']) {
            $sanitized = self::redactJwts($sanitized);
            // Bearer tokens may include dots (JWT-shaped `abc.def.ghi`, opaque
            // dotted tokens) — include `.` in the character class so the whole
            // credential is consumed, not just the first segment.
            $sanitized = self::replaceAtWordStart($sanitized, self::BEARER_BODY, 'i', 'Bearer [TOKEN_REDACTED]');
            $sanitized = self::replaceAtWordStart($sanitized, self::OAUTH_TOKEN_ASSIGNMENT_BODY, 'i', 'access_token=[TOKEN_REDACTED]');
        }

        if ($options['redactApiKeys']) {
            $sanitized = self::redactSecretShapes($sanitized);
            $sanitized = self::replaceAtWordStart($sanitized, self::PREFIXED_KEY_BODY, '', '[API_KEY_REDACTED]');
            $sanitized = self::pcre(preg_replace(self::LONG_TOKEN, '[POTENTIAL_KEY_REDACTED]', $sanitized));
            $sanitized = self::redactEncodedKeys($sanitized);
        }

        if ($options['redactEmails']) {
            $sanitized = self::redactPlatformEmails($sanitized);
        }

        if ($options['redactPasswords']) {
            $sanitized = self::redactPasswordShapes($sanitized);
        }

        if ($options['redactPhoneNumbers']) {
            $sanitized = self::redactPlatformPhones($sanitized);
        }

        if ($options['redactCreditCards']) {
            $sanitized = self::redactCardShapes($sanitized);
        }

        if ($options['redactIpAddresses']) {
            $sanitized = self::replaceAtWordStart($sanitized, self::IPV4_BODY, '', '[IP_REDACTED]');
            $sanitized = self::replaceAtWordStart($sanitized, self::IPV6_BODY, '', '[IPV6_REDACTED]');
        }

        if ($options['redactUuids']) {
            $sanitized = self::pcre(preg_replace(self::UUID, '[ID]', $sanitized));
        }

        if ($options['redactUrls']) {
            $sanitized = self::pcre(preg_replace_callback(
                self::URL_WITH_QUERY,
                static fn (array $match): string => self::withoutQuery($match[0]),
                $sanitized,
            ));
        }

        return $sanitized;
    }

    /**
     * Mirrors `redactFieldName`: a field NAME meets the rules its value meets,
     * minus the identifier-shaped ones (PAPI-4960) — `prefixedKey`,
     * `longToken`, `encodedKey`, `uuid`, `ipv4`, `ipv6` and `url` are left off,
     * because a name is the join surface a query names and `token_expires_at`
     * is not a credential.
     *
     * @param array<string, bool> $options
     */
    private static function redactFieldName(string $key, array $options): string
    {
        if ($key === '') {
            return $key;
        }
        try {
            $sanitized = $key;
            if ($options['redactTokens']) {
                $sanitized = self::redactJwts($sanitized);
                $sanitized = self::replaceAtWordStart($sanitized, self::BEARER_BODY, 'i', 'Bearer [TOKEN_REDACTED]');
                $sanitized = self::replaceAtWordStart($sanitized, self::OAUTH_TOKEN_ASSIGNMENT_BODY, 'i', 'access_token=[TOKEN_REDACTED]');
            }
            if ($options['redactApiKeys']) {
                $sanitized = self::redactSecretShapes($sanitized);
            }
            if ($options['redactEmails']) {
                $sanitized = self::redactPlatformEmails($sanitized);
            }
            if ($options['redactPasswords']) {
                $sanitized = self::redactPasswordShapes($sanitized);
            }
            if ($options['redactPhoneNumbers']) {
                $sanitized = self::redactPlatformPhones($sanitized);
            }
            if ($options['redactCreditCards']) {
                $sanitized = self::redactCardShapes($sanitized);
            }
            return $sanitized;
        } catch (LoggerException) {
            return self::REDACTION_FAILED;
        }
    }

    private static function redactSecretShapes(string $text): string
    {
        static $pattern = null;
        $pattern ??= '~(?:(?<![A-Za-z0-9])|' . self::AFTER_JSON_ESCAPE . ')(?:'
            . implode('|', array_map(static fn (string $source): string => "(?:{$source})", self::SECRET_SHAPE_SOURCES))
            . ')(?![A-Za-z0-9])~';
        return self::pcre(preg_replace($pattern, '[API_KEY_REDACTED]', $text));
    }

    private static function redactPasswordShapes(string $text): string
    {
        $text = self::pcre(preg_replace(self::PASSWORD_JSON, '"password": "[PASSWORD_REDACTED]"', $text));
        return self::pcre(preg_replace(self::PASSWORD_ASSIGNMENT, 'password=[PASSWORD_REDACTED]', $text));
    }

    private static function redactCardShapes(string $text): string
    {
        $text = self::replaceAtWordStart($text, self::CARD_BODY, '', '[CARD_REDACTED]');
        return self::replaceAtWordStart($text, self::CVV_BODY, 'i', 'cvv=[CVV_REDACTED]');
    }

    /**
     * Mirrors `replaceAtWordStart`: a rule anchored on `\b` before a word
     * character runs as two patterns — the anchored one, and the same body
     * straight after a JSON string escape, where `\b` does not fall (in
     * `"card:\n4111 1111 1111 1111"` the card follows the `n` of an escaped
     * newline). The escape is captured and put back (PAPI-4859).
     */
    private static function replaceAtWordStart(string $text, string $body, string $flags, string $replacement): string
    {
        $start = $flags === 'i' ? self::CASELESS_START : '';
        if (str_contains($text, '\\')) {
            $text = self::pcre(preg_replace(
                '~' . $start . self::JSON_ESCAPE_PREFIX . $body . '~' . $flags,
                '$1' . $replacement,
                $text,
            ));
        }
        return self::pcre(preg_replace('~' . $start . self::WORD_START . $body . '~' . $flags, $replacement, $text));
    }

    /**
     * Every preg_* result passes through here. A PCRE error (backtrack limit,
     * JIT stack limit) is a `null`/`false` result; it becomes an exception that
     * {@see self::redactString()} and {@see self::redactFieldName()} turn into
     * {@see self::REDACTION_FAILED}, so a failed rule can never hand back the
     * text it was meant to rewrite.
     *
     * @template T
     * @param T|null|false $result
     * @return T
     */
    private static function pcre(mixed $result): mixed
    {
        if ($result === null || $result === false || preg_last_error() !== PREG_NO_ERROR) {
            throw new LoggerException('PII redaction failed: ' . preg_last_error_msg());
        }
        return $result;
    }

    // ── JWTs ──────────────────────────────────────────────────────────────

    /** Mirrors `redactJwts`: the JWT rule, one scan per token run. */
    private static function redactJwts(string $text): string
    {
        if (!str_contains($text, 'eyJ')) {
            return $text;
        }
        $length = strlen($text);
        $out = '';
        $cursor = 0;
        $index = 0;
        // The next `eyJ` at or after the scan, found once per occurrence so
        // the scan stays linear however many runs lie between two of them.
        $nextEyJ = -1;
        while ($index < $length) {
            $index += strcspn($text, self::JWT_CHARS, $index);
            if ($index >= $length) {
                break;
            }
            $runStart = $index;
            $index += strspn($text, self::JWT_CHARS, $index);
            $start = self::leftmostJwtStart($text, $runStart, $index, $nextEyJ);
            if ($start === -1) {
                continue;
            }
            $payloadEnd = self::jwtRunAfterDot($text, $index);
            if ($payloadEnd === -1) {
                continue;
            }
            $signatureEnd = self::jwtRunAfterDot($text, $payloadEnd);
            if ($signatureEnd === -1) {
                continue;
            }
            $out .= substr($text, $cursor, $start - $cursor) . self::JWT_REDACTION;
            $cursor = $signatureEnd;
            $index = $signatureEnd;
        }
        return $cursor === 0 ? $text : $out . substr($text, $cursor);
    }

    private static function leftmostJwtStart(string $text, int $from, int $to, int &$nextEyJ): int
    {
        $at = $from;
        while (true) {
            if ($nextEyJ !== PHP_INT_MAX && $nextEyJ < $at) {
                $found = strpos($text, 'eyJ', $at);
                $nextEyJ = $found === false ? PHP_INT_MAX : $found;
            }
            if ($nextEyJ === PHP_INT_MAX || $nextEyJ + 3 >= $to) {
                return -1;
            }
            $candidate = $nextEyJ;
            if ($candidate === 0
                || !str_contains(self::WORD_CHARS, $text[$candidate - 1])
                || self::closesJsonEscape($text, $candidate)
            ) {
                return $candidate;
            }
            $at = $candidate + 1;
        }
    }

    private static function closesJsonEscape(string $text, int $at): bool
    {
        $from = max(0, $at - 6);
        return self::pcre(preg_match(self::JSON_ESCAPE_BEHIND, substr($text, $from, $at - $from))) === 1;
    }

    private static function jwtRunAfterDot(string $text, int $at): int
    {
        if (($text[$at] ?? '') !== '.') {
            return -1;
        }
        $end = $at + 1 + strspn($text, self::JWT_CHARS, $at + 1);
        return $end > $at + 1 ? $end : -1;
    }

    // ── Emails ────────────────────────────────────────────────────────────

    /**
     * Mirrors `redactPlatformEmails`: the email rule, one left-to-right scan.
     * An address starts at the first word boundary of the run of local-part
     * characters before its `@` (skipping a JSON escape's letter), and its
     * host is matched from just after the `@`.
     */
    private static function redactPlatformEmails(string $text): string
    {
        $at = strpos($text, '@');
        if ($at === false) {
            return $text;
        }
        $out = '';
        $cursor = 0;
        // No run of local-part characters reaches back past this point.
        $floor = 0;
        while ($at !== false) {
            $runStart = self::localRunStart($text, $floor, $at);
            $start = self::firstWordBoundary($text, $runStart, $at);
            $floor = $at + 1;
            if ($start !== -1) {
                $start += self::jsonEscapeLength($text, $start);
            }
            if ($start === -1 || $start >= $at) {
                $at = strpos($text, '@', $at + 1);
                continue;
            }
            $hostEnd = self::emailHostEnd($text, $at + 1);
            if ($hostEnd === -1) {
                $at = strpos($text, '@', $at + 1);
                continue;
            }
            $out .= substr($text, $cursor, $start - $cursor) . self::EMAIL_REDACTION;
            $cursor = $hostEnd;
            $floor = $cursor;
            $at = strpos($text, '@', $cursor);
        }
        return $cursor === 0 ? $text : $out . substr($text, $cursor);
    }

    /**
     * Where `PLATFORM_HOST` — `[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?`
     * labels joined by `.`, then `\b` — matching at `$at` ends, or -1.
     *
     * A scan rather than the regex: on a host of tens of thousands of labels
     * the regex's `(?:\.label)*` exhausts PCRE's JIT stack, which would turn
     * the whole string into {@see self::REDACTION_FAILED} where the TypeScript
     * redacts the address. The scan reaches the regex's answer by the regex's
     * own backtracking order: every label but the last at its longest (each
     * is followed by the `.` that the next one needs, so `\b` always holds
     * after it), then the last label's longest end that `\b` allows, then —
     * if it has none — the label before it.
     */
    private static function emailHostEnd(string $text, int $at): int
    {
        static $alphanumeric = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        static $labelChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-';
        $length = strlen($text);
        $previousEnd = -1;
        $labelStart = $at;
        while (true) {
            if ($labelStart >= $length || !str_contains($alphanumeric, $text[$labelStart])) {
                return $previousEnd;
            }
            // The label's longest end: its last alphanumeric within 63 characters.
            $runEnd = $labelStart + 1 + strspn($text, $labelChars, $labelStart + 1, 62);
            $labelEnd = $runEnd;
            while ($labelEnd > $labelStart + 1 && $text[$labelEnd - 1] === '-') {
                $labelEnd--;
            }
            if (($text[$labelEnd] ?? '') === '.' && isset($text[$labelEnd + 1]) && str_contains($alphanumeric, $text[$labelEnd + 1])) {
                $previousEnd = $labelEnd;
                $labelStart = $labelEnd + 1;
                continue;
            }
            // The last label: its longest end not followed by a word character.
            for ($end = $labelEnd; $end > $labelStart; $end--) {
                if (str_contains($alphanumeric, $text[$end - 1])
                    && !(isset($text[$end]) && str_contains(self::WORD_CHARS, $text[$end]))
                ) {
                    return $end;
                }
            }
            return $previousEnd;
        }
    }

    /** Just past the last non-local-part character in [$from, $to), or $from. */
    private static function localRunStart(string $text, int $from, int $to): int
    {
        $runStart = $from;
        $index = $from;
        while ($index < $to) {
            $index += strspn($text, self::EMAIL_LOCAL_CHARS, $index, $to - $index);
            if ($index >= $to) {
                break;
            }
            $index++;
            $runStart = $index;
        }
        return $runStart;
    }

    /** The first k in [$from, $to) where word-ness changes between k-1 and k, or -1. */
    private static function firstWordBoundary(string $text, int $from, int $to): int
    {
        if ($from >= $to) {
            return -1;
        }
        $before = $from > 0 && str_contains(self::WORD_CHARS, $text[$from - 1]);
        $here = str_contains(self::WORD_CHARS, $text[$from]);
        if ($before !== $here) {
            return $from;
        }
        $next = $from + ($here
            ? strspn($text, self::WORD_CHARS, $from, $to - $from)
            : strcspn($text, self::WORD_CHARS, $from, $to - $from));
        return $next < $to ? $next : -1;
    }

    /** Mirrors `jsonEscapeLength`. */
    private static function jsonEscapeLength(string $text, int $index): int
    {
        $backslashes = 0;
        while ($backslashes < 64 && $index - $backslashes - 1 >= 0 && $text[$index - $backslashes - 1] === '\\') {
            $backslashes++;
        }
        if ($backslashes % 2 === 0) {
            return 0;
        }
        $letter = $text[$index] ?? '';
        if ($letter !== '' && str_contains('nrtbf', $letter)) {
            return 1;
        }
        if ($letter !== 'u') {
            return 0;
        }
        $hex = 0;
        while ($hex < 4 && isset($text[$index + 1 + $hex]) && str_contains(self::HEX_DIGITS, $text[$index + 1 + $hex])) {
            $hex++;
        }
        return 1 + $hex;
    }

    // ── Phones ────────────────────────────────────────────────────────────

    /** Mirrors `redactPlatformPhones`. */
    private static function redactPlatformPhones(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        return self::replaceOnFoldedView(
            self::absorbPhoneTails($text),
            self::PHONE,
            static function (string $matched, int $foldedIndex, string $folded): string|array|null {
                if (self::pcre(preg_match(self::INTERNATIONAL_NUMBER, $matched)) === 1) {
                    [$end, $digits] = self::phoneTail($folded, $foldedIndex + strlen($matched), strlen($matched) - 1);
                    if ($digits < self::MIN_INTERNATIONAL_DIGITS) {
                        return null;
                    }
                    return [self::PHONE_REDACTION, $end];
                }
                $formatted = self::pcre(preg_match(self::PHONE_FORMATTING, $matched)) === 1 || $matched[0] === '+';
                if (!$formatted && !self::hasPhoneContext($folded, $foldedIndex, $foldedIndex + strlen($matched))) {
                    return null;
                }
                return self::PHONE_REDACTION;
            },
        );
    }

    /** Mirrors `absorbPhoneTails`: a marker swallows the national groups after it. */
    private static function absorbPhoneTails(string $text): string
    {
        if (!str_contains($text, self::PHONE_REDACTION)) {
            return $text;
        }
        return self::replaceOnFoldedView(
            $text,
            self::PHONE_MARKER,
            static fn (string $matched, int $foldedIndex, string $folded): array => [
                self::PHONE_REDACTION,
                self::phoneTail($folded, $foldedIndex + strlen(self::PHONE_REDACTION), self::MARKER_DIGITS)[0],
            ],
        );
    }

    /**
     * Mirrors `phoneTail`: how far the digit groups after a number reach, and
     * how many digits the number then has.
     *
     * @return array{0: int, 1: int} [end, digits]
     */
    private static function phoneTail(string $folded, int $at, int $digits): array
    {
        $length = strlen($folded);
        $end = $at;
        $taken = $digits;
        while (true) {
            $next = $end + min(3, strspn($folded, self::TAIL_SEPARATORS, min($end, $length)));
            if (substr($folded, $next, strlen(self::PHONE_REDACTION)) === self::PHONE_REDACTION) {
                $end = $next + strlen(self::PHONE_REDACTION);
                $taken = max($taken, self::MARKER_DIGITS);
                continue;
            }
            if (self::pcre(preg_match(self::NOT_A_PHONE_GROUP, $folded, $unused, 0, $next)) === 1) {
                break;
            }
            $count = min(11, strspn($folded, self::DIGITS, $next));
            $run = $next + $count;
            if ($count === 0 || $count > 10) {
                break;
            }
            if ($run < $length && str_contains(self::TOKEN_CHARS, $folded[$run])) {
                break;
            }
            if ($taken + $count > self::E164_MAX_DIGITS) {
                break;
            }
            $complete = $taken >= self::COMPLETE_PHONE_DIGITS;
            if ($complete && $count > self::FINAL_GROUP_MAX_DIGITS) {
                break;
            }
            if ($taken >= self::STATUS_CODE_AFTER_DIGITS && $count === 3 && $folded[$next] >= '1' && $folded[$next] <= '5') {
                break;
            }
            if ($taken >= self::STATUS_CODE_AFTER_DIGITS
                && self::pcre(preg_match(self::UNIT_AFTER_GROUP, $folded, $unused, 0, $run)) === 1
            ) {
                break;
            }
            $taken += $count;
            $end = $run;
            if ($complete) {
                break;
            }
        }
        return [$end, $taken];
    }

    /**
     * Whether a phone word sits within {@see self::PHONE_CONTEXT_RADIUS}
     * characters of the match, counting characters as JavaScript's string
     * indexes do (UTF-16 code units), not as bytes.
     */
    private static function hasPhoneContext(string $folded, int $start, int $end): bool
    {
        $from = $start;
        for ($units = 0; $from > 0;) {
            $lead = $from - 1;
            while ($lead > 0 && $from - $lead < 4 && (ord($folded[$lead]) & 0xC0) === 0x80) {
                $lead--;
            }
            $units += ord($folded[$lead]) >= 0xF0 ? 2 : 1;
            if ($units > self::PHONE_CONTEXT_RADIUS) {
                break;
            }
            $from = $lead;
        }
        $length = strlen($folded);
        $to = $end;
        for ($units = 0; $to < $length;) {
            $lead = ord($folded[$to]);
            $width = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : ($lead >= 0xC0 ? 2 : 1));
            $units += $lead >= 0xF0 ? 2 : 1;
            if ($units > self::PHONE_CONTEXT_RADIUS) {
                break;
            }
            $to = min($length, $to + $width);
        }
        $context = strtr(substr($folded, $from, $to - $from), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        foreach (self::PHONE_CONTEXT_WORDS as $word) {
            if (str_contains($context, $word)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mirrors `replaceOnFoldedView`: run `$pattern` over the folded view of
     * `$text` one match at a time, let `$decide` keep the match (`null`),
     * replace it (a string), or replace it and everything up to a folded end
     * (`[replacement, foldedEnd]`), and splice each replacement over the
     * ORIGINAL characters the match was folded from.
     *
     * @param callable(string, int, string): (string|array{0: string, 1: int}|null) $decide
     */
    private static function replaceOnFoldedView(string $text, string $pattern, callable $decide): string
    {
        if ($text === '') {
            return $text;
        }
        $view = self::foldForMatching($text);
        $folded = $view['text'];
        $out = '';
        $cursor = 0;
        $replaced = false;
        $offset = 0;
        $foldedLength = strlen($folded);
        while ($offset <= $foldedLength
            && self::pcre(preg_match($pattern, $folded, $match, PREG_OFFSET_CAPTURE, $offset)) === 1
        ) {
            [$matched, $foldedIndex] = $match[0];
            if ($matched === '') {
                $offset = $foldedIndex + 1;
                continue;
            }
            $offset = $foldedIndex + strlen($matched);
            $start = self::originalStart($view, $foldedIndex);
            if ($start < $cursor) {
                continue;
            }
            $answer = $decide($matched, $foldedIndex, $folded);
            if ($answer === null) {
                continue;
            }
            $foldedEnd = $foldedIndex + strlen($matched);
            if (is_array($answer)) {
                [$replacement, $answerEnd] = $answer;
                $foldedEnd = max($foldedIndex + 1, $answerEnd);
                $offset = $foldedEnd;
            } else {
                $replacement = $answer;
            }
            $out .= substr($text, $cursor, $start - $cursor) . $replacement;
            $cursor = $foldedEnd > $foldedIndex
                ? self::originalEnd($view, $foldedEnd - 1)
                : $start;
            $replaced = true;
        }
        return $replaced ? $out . substr($text, $cursor) : $text;
    }

    /**
     * Mirrors `foldForMatching`, plus the `PHONE_FOLD_NEEDED` gate in front of
     * it: the text the phone rule matches on, with Unicode spaces and dashes,
     * fullwidth and other compatibility forms, `&nbsp;` entities and @-lookalikes
     * folded to ASCII, format characters and quoted-printable soft line breaks
     * dropped — and the way back from each folded byte to the original.
     *
     * The way back is kept as spans rather than per-byte arrays: each span is
     * one original character (or entity, or soft break) that folded to
     * something else, and every byte between spans maps across unchanged. A
     * text that needs no fold comes back as itself with no spans. The spans
     * are packed four unsigned 32-bit integers apiece (folded offset, folded
     * length, original offset, original length) into one string — 16 bytes a
     * span, where four PHP int arrays cost about 64 and put a megabyte of NBSP
     * past a 128 MB `memory_limit`.
     *
     * @return array{text: string, spans: string}
     */
    private static function foldForMatching(string $text): array
    {
        static $plain = null;
        // Bytes copied across as they are: ASCII but `=` (soft breaks) and `&` (entities).
        $plain ??= str_replace(['=', '&'], '', implode('', array_map('chr', range(0, 0x7F))));

        $length = strlen($text);
        $needed = false;
        $folded = '';
        $copied = 0;
        $spans = '';
        $index = 0;
        while (true) {
            $index += strspn($text, $plain, $index);
            if ($index >= $length) {
                break;
            }
            $byte = ord($text[$index]);
            if ($byte === 0x3D) {
                $soft = ($text[$index + 1] ?? '') === "\n" ? 2 : (substr($text, $index + 1, 2) === "\r\n" ? 3 : 0);
                if ($soft === 0) {
                    $index++;
                    continue;
                }
                $needed = true;
                $replacement = '';
                $width = $soft;
            } elseif ($byte === 0x26) {
                // The gate reads at most the longest entity it can match
                // (`&#x` + eight zeros + `a0;`, 14 bytes): on the whole
                // subject the JIT searches to its end for the `;` at every
                // `&`, and a megabyte of `a=1&` took seven seconds.
                if (self::pcre(preg_match(self::NBSP_ENTITY, substr($text, $index, 14), $unused)) === 1) {
                    $needed = true;
                }
                if (self::pcre(preg_match(self::NBSP_ENTITY, substr($text, $index, 10), $entity)) !== 1) {
                    $index++;
                    continue;
                }
                $replacement = ' ';
                $width = strlen($entity[0]);
            } else {
                [$codePoint, $width] = self::codePointAt($text, $index, $length);
                if (!self::foldNotNeeded($codePoint)) {
                    $needed = true;
                }
                if ($codePoint >= 0xFF01 && $codePoint <= 0xFF5E) {
                    $replacement = chr($codePoint - 0xFEE0);
                } else {
                    $replacement = RedactPiiFoldTable::FOLD[substr($text, $index, $width)] ?? null;
                    if ($replacement === null) {
                        $index += $width;
                        continue;
                    }
                }
            }
            $folded .= substr($text, $copied, $index - $copied);
            $spans .= pack('V4', strlen($folded), strlen($replacement), $index, $width);
            $folded .= $replacement;
            $index += $width;
            $copied = $index;
        }
        if (!$needed) {
            return ['text' => $text, 'spans' => ''];
        }
        return ['text' => $folded . substr($text, $copied), 'spans' => $spans];
    }

    /**
     * Mirrors `PHONE_FOLD_NEEDED`'s character class: the scripts a text can be
     * written in without needing the fold.
     */
    private static function foldNotNeeded(int $codePoint): bool
    {
        return ($codePoint >= 0xC0 && $codePoint <= 0x24F)
            || ($codePoint >= 0x400 && $codePoint <= 0x4FF)
            || $codePoint === 0x3001
            || $codePoint === 0x3002
            || ($codePoint >= 0x3041 && $codePoint <= 0x3096)
            || ($codePoint >= 0x30A1 && $codePoint <= 0x30FF)
            || ($codePoint >= 0x4E00 && $codePoint <= 0x9FFF)
            || ($codePoint >= 0xAC00 && $codePoint <= 0xD7A3);
    }

    /**
     * The code point of the UTF-8 sequence at `$index` and its width in bytes.
     * A byte that does not start a valid sequence is one character of its own,
     * code point -1: it folds to nothing else and asks for the fold, as an
     * unpaired surrogate does in JavaScript.
     *
     * @return array{0: int, 1: int}
     */
    private static function codePointAt(string $text, int $index, int $length): array
    {
        $lead = ord($text[$index]);
        [$width, $min] = match (true) {
            $lead >= 0xC2 && $lead <= 0xDF => [2, 0x80],
            $lead >= 0xE0 && $lead <= 0xEF => [3, 0x800],
            $lead >= 0xF0 && $lead <= 0xF4 => [4, 0x10000],
            default => [0, 0],
        };
        if ($width === 0 || $index + $width > $length) {
            return [-1, 1];
        }
        $codePoint = $lead & (0xFF >> ($width + 1));
        for ($k = 1; $k < $width; $k++) {
            $continuation = ord($text[$index + $k]);
            if (($continuation & 0xC0) !== 0x80) {
                return [-1, 1];
            }
            $codePoint = ($codePoint << 6) | ($continuation & 0x3F);
        }
        if ($codePoint < $min || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            return [-1, 1];
        }
        return [$codePoint, $width];
    }

    /**
     * The last span starting at or before folded byte `$at`, as
     * [foldedAt, foldedLength, originalAt, originalLength], or null.
     *
     * @param array{text: string, spans: string} $view
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private static function spanAt(array $view, int $at): ?array
    {
        $low = 0;
        $high = intdiv(strlen($view['spans']), 16) - 1;
        $found = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $span = array_values(unpack('V4', $view['spans'], $middle * 16));
            if ($span[0] <= $at) {
                $found = $span;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        return $found;
    }

    /**
     * Where the original character that folded byte `$at` came from starts.
     *
     * @param array{text: string, spans: string} $view
     */
    private static function originalStart(array $view, int $at): int
    {
        $span = self::spanAt($view, $at);
        if ($span === null) {
            return $at;
        }
        [$foldedAt, $foldedLength, $originalAt, $originalLength] = $span;
        return $at < $foldedAt + $foldedLength
            ? $originalAt
            : $at - $foldedAt - $foldedLength + $originalAt + $originalLength;
    }

    /**
     * Where the original character that folded byte `$at` came from ends.
     *
     * @param array{text: string, spans: string} $view
     */
    private static function originalEnd(array $view, int $at): int
    {
        $span = self::spanAt($view, $at);
        if ($span !== null && $at < $span[0] + $span[1]) {
            return $span[2] + $span[3];
        }
        return self::originalStart($view, $at) + 1;
    }

    // ── Encoded runs and URL paths (FLT-1107) ─────────────────────────────

    /**
     * Mirrors `redactEncodedKeys`: `ENCODED_KEY`, keeping a URL's host and the
     * words of its path. WHETHER the rule fires is unchanged — it sees the
     * same text and the same 40-character runs — but a run that starts in a
     * `scheme://[userinfo@]host[:port]` host keeps that prefix and is judged
     * from the path on, and a path run loses only its non-word pieces.
     */
    private static function redactEncodedKeys(string $text): string
    {
        // Hosts are found as the runs reach them, never listed up front: a
        // megabyte of `://` holds 300 000 of them.
        $host = self::nextUrlHost($text, 0);
        return self::pcre(preg_replace_callback(
            self::ENCODED_KEY,
            static function (array $match) use ($text, &$host): string {
                [$run, $start] = $match[0];
                $next = $text[$start + strlen($run)] ?? '';
                while ($host !== null && $host[1] <= $start) {
                    $host = self::nextUrlHost($text, $host[2]);
                }
                if ($host === null || $start < $host[0]) {
                    return self::redactEncodedRun($run, $next);
                }
                // The run starts in this host and can only leave it through a
                // `/`; a run that stays inside it is judged whole, as before.
                $hostPart = $host[1] - $start;
                if (($run[$hostPart] ?? '') !== '/') {
                    return self::redactEncodedRun($run, $next);
                }
                return substr($run, 0, $hostPart + 1) . self::redactEncodedRun(substr($run, $hostPart + 1), $next);
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        ));
    }

    /**
     * Mirrors `urlHosts`, one host at a time: the first non-empty
     * `scheme://[userinfo@]host[:port]` host (and port) whose `://` sits at or
     * after `$from`, as [start, end, where to look for the next one].
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function nextUrlHost(string $text, int $from): ?array
    {
        $length = strlen($text);
        for ($separator = strpos($text, '://', $from); $separator !== false; $separator = strpos($text, '://', $separator + 3)) {
            $start = $separator + 3;
            $authorityEnd = $start;
            while ($authorityEnd < $length) {
                $authorityEnd += strcspn($text, "\t\n\x0B\f\r /?#@\xC2\xE1\xE2\xE3\xEF", $authorityEnd);
                if ($authorityEnd >= $length) {
                    break;
                }
                $byte = $text[$authorityEnd];
                if ($byte === '@') {
                    $start = $authorityEnd + 1;
                    $authorityEnd++;
                    continue;
                }
                if (ord($byte) >= 0x80 && self::pcre(preg_match('~\A' . self::SPACE . '~', substr($text, $authorityEnd, 3))) !== 1) {
                    $authorityEnd++;
                    continue;
                }
                break;
            }
            $end = $start + strspn($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-', $start, max(0, $authorityEnd - $start));
            if (($text[$end] ?? '') === ':') {
                $end++;
                $end += strspn($text, self::DIGITS, $end, max(0, $authorityEnd - $end));
            }
            if ($end > $start) {
                return [$start, $end, $separator + 3];
            }
        }
        return null;
    }

    /**
     * Mirrors `redactEncodedRun`: one `ENCODED_KEY` run, or the path part of
     * one. It goes WHOLE when it holds `+` or `=` padding (base64 markers a
     * path does not have) or does not read as a path; otherwise only its
     * non-word pieces are redacted.
     */
    private static function redactEncodedRun(string $run, string $next): string
    {
        if ($next === '=' || strpbrk($run, '+=') !== false) {
            return self::ENCODED_KEY_REDACTION;
        }
        // Two passes over the `/`-pieces, never an array of them: a run can be
        // a megabyte of `a/`. The first counts and remembers each piece's
        // verdict as one byte; the second writes the result.
        $length = strlen($run);
        $kept = '';
        $words = 0;
        $others = 0;
        $nameWords = 0;
        for ($at = 0; $at <= $length; $at = $end + 1) {
            $end = $at + strcspn($run, '/', $at);
            $piece = substr($run, $at, $end - $at);
            $isKept = $piece === '' || self::isUrlPathWord($piece);
            $kept .= $isKept ? '1' : '0';
            if (!$isKept) {
                $others++;
            } elseif (strlen($piece) >= 2 && strpbrk($piece, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') !== false) {
                $words++;
            }
            if (self::pcre(preg_match(self::URL_PATH_NAME_WORD, $piece)) === 1) {
                $nameWords++;
            }
        }
        if ($words < 4 || $words <= $others || $nameWords < 2) {
            return self::ENCODED_KEY_REDACTION;
        }
        $out = '';
        for ($at = 0, $i = 0; $at <= $length; $at = $end + 1, $i++) {
            $end = $at + strcspn($run, '/', $at);
            $out .= ($i === 0 ? '' : '/') . ($kept[$i] === '1' ? substr($run, $at, $end - $at) : self::ENCODED_KEY_REDACTION);
        }
        return $out;
    }

    /** Mirrors `isUrlPathWord`: a `/`-piece of a run that reads as a path word or number. */
    private static function isUrlPathWord(string $piece): bool
    {
        if (self::pcre(preg_match(self::URL_PATH_WORD, $piece)) !== 1) {
            return false;
        }
        if (strspn($piece, self::DIGITS) === strlen($piece)) {
            return true;
        }
        if (strlen($piece) >= 4
            && self::pcre(preg_match(self::URL_PATH_CONSONANTS, self::pcre(preg_replace(self::URL_PATH_CLUSTER, 'x', $piece)))) === 1
        ) {
            return false;
        }
        return strlen($piece) < 8 || self::pcre(preg_match(self::URL_PATH_LONG_WORD, $piece)) === 1;
    }

    // ── URL query strings ─────────────────────────────────────────────────

    /**
     * The `redactUrls` rule for one `https?://…` match: the URL without its
     * query, as JavaScript's `${url.protocol}//${url.host}${url.pathname}` puts
     * it, when the URL has a non-empty query; the match unchanged when it has
     * none. A URL that does not parse still loses its query (FLT-1107): an
     * earlier rule can leave one unparseable (an `[IP_REDACTED]` host, a
     * redacted path run), and keeping the query then is the reverse of what
     * this rule is for.
     */
    private static function withoutQuery(string $match): string
    {
        $parsed = self::parseHttpUrl($match);
        if ($parsed === null) {
            $query = strpos($match, '?');
            return $query === false ? $match : substr($match, 0, $query) . '?[QUERY_REDACTED]';
        }
        [$withoutQuery, $hasQuery] = $parsed;
        return $hasQuery ? $withoutQuery . '?[QUERY_REDACTED]' : $match;
    }

    /**
     * The WHATWG URL parse `new URL()` runs, for the `http:`/`https:` URLs the
     * rule matches, as far as `protocol//host + pathname` and "is the query
     * empty" need it: the userinfo and fragment dropped, the host lowercased
     * and percent-decoded, an IPv4 or IPv6 host normalised, a default port
     * dropped, `\` read as `/`, dot segments resolved and the path
     * percent-encoded. Null where `new URL()` throws — and also for a host
     * outside ASCII, which JavaScript would convert to punycode; such a URL
     * then loses its query through the unparseable branch, as written.
     *
     * @return array{0: string, 1: bool}|null [protocol//host + pathname, has a non-empty query]
     */
    private static function parseHttpUrl(string $url): ?array
    {
        $url = rtrim($url, "\x00..\x20");
        $secure = str_starts_with($url, 'https://');
        $protocol = $secure ? 'https:' : 'http:';
        $length = strlen($url);
        $at = strlen($protocol) + 2;
        $at += strspn($url, '/\\', $at);

        $authorityEnd = $at + strcspn($url, '/\\?#', $at);
        $authority = substr($url, $at, $authorityEnd - $at);
        $userinfoEnd = strrpos($authority, '@');
        if ($userinfoEnd !== false) {
            $authority = substr($authority, $userinfoEnd + 1);
        }
        $portAt = self::portSeparator($authority);
        $host = $portAt === -1 ? $authority : substr($authority, 0, $portAt);
        $port = $portAt === -1 ? '' : substr($authority, $portAt + 1);
        if ($host === '') {
            return null;
        }
        $host = self::parseHost($host);
        if ($host === null) {
            return null;
        }
        if ($port !== '') {
            if (strspn($port, self::DIGITS) !== strlen($port)) {
                return null;
            }
            $port = ltrim($port, '0');
            if (strlen($port) > 5 || (int) $port > 65535) {
                return null;
            }
            $port = (int) $port === ($secure ? 443 : 80) ? '' : ':' . (int) $port;
        }

        $at = $authorityEnd;
        if ($at < $length && ($url[$at] === '/' || $url[$at] === '\\')) {
            $at++;
        }
        $pathEnd = $at + strcspn($url, '?#', $at);
        $pathname = self::pathname(substr($url, $at, $pathEnd - $at));

        $hasQuery = false;
        if ($pathEnd < $length && $url[$pathEnd] === '?') {
            $hasQuery = strcspn($url, '#', $pathEnd + 1) > 0;
        }
        return ["{$protocol}//{$host}{$port}{$pathname}", $hasQuery];
    }

    /**
     * A URL path as `url.pathname` gives it: `\\` read as `/`, `.` and `..`
     * segments (and their `%2e` spellings) resolved, and the bytes WHATWG's
     * path percent-encode set names percent-encoded.
     *
     * A path with no dot segment needs no splitting at all. One with them is
     * walked piece by piece with a stack holding one integer per surviving
     * piece — its offset and length packed together — never an array of the
     * pieces themselves, which cost about 37 MB per megabyte of path.
     */
    private static function pathname(string $path): string
    {
        if (self::pcre(preg_match(self::DOT_SEGMENT, $path)) !== 1) {
            return self::percentEncodePath('/' . strtr($path, '\\', '/'));
        }
        $length = strlen($path);
        $segments = [];
        for ($at = 0; $at <= $length; $at = $end + 1) {
            $end = $at + strcspn($path, '/\\', $at);
            $dots = $end - $at > 6 ? 0 : match (strtolower(substr($path, $at, $end - $at))) {
                '.', '%2e' => 1,
                '..', '.%2e', '%2e.', '%2e%2e' => 2,
                default => 0,
            };
            $last = $end >= $length;
            if ($dots === 2) {
                array_pop($segments);
            }
            // Offset and length share one int, which assumes 64-bit PHP; 32-bit
            // builds are out of scope for this SDK (composer requires php ^8.1).
            if ($dots === 0) {
                $segments[] = ($at << 32) | ($end - $at);
            } elseif ($last) {
                $segments[] = $end << 32;
            }
        }
        $pathname = '';
        foreach ($segments as $segment) {
            $pathname .= '/' . substr($path, $segment >> 32, $segment & 0xFFFFFFFF);
        }
        return self::percentEncodePath($pathname === '' ? '/' : $pathname);
    }

    /** WHATWG's path percent-encode set: C0, space, `"`, `<`, `>`, `` ` ``, `{`, `}`, DEL and non-ASCII. */
    private static function percentEncodePath(string $path): string
    {
        return self::pcre(preg_replace_callback(
            '~[\x00-\x20"<>`{}\x7F-\xFF]++~',
            static fn (array $bytes): string => self::pcre(preg_replace('~..~s', '%$0', strtoupper(bin2hex($bytes[0])))),
            $path,
        ));
    }

    /** Where the `:` before an authority's port is, or -1 (a `:` inside `[…]` is IPv6). */
    private static function portSeparator(string $authority): int
    {
        $inBrackets = false;
        for ($i = 0, $n = strlen($authority); $i < $n; $i++) {
            $byte = $authority[$i];
            if ($byte === '[') {
                $inBrackets = true;
            } elseif ($byte === ']') {
                $inBrackets = false;
            } elseif ($byte === ':' && !$inBrackets) {
                return $i;
            }
        }
        return -1;
    }

    /** A WHATWG host, serialised; null where the parse fails or would need punycode. */
    private static function parseHost(string $host): ?string
    {
        if ($host[0] === '[') {
            if (!str_ends_with($host, ']') || !function_exists('inet_pton')) {
                return null;
            }
            $inner = substr($host, 1, -1);
            $packed = strspn($inner, '0123456789abcdefABCDEF:.') === strlen($inner) ? @inet_pton($inner) : false;
            return $packed === false || strlen($packed) !== 16 ? null : '[' . self::serializeIpv6($packed) . ']';
        }
        $host = rawurldecode($host);
        if (strcspn($host, self::NON_ASCII_BYTES) !== strlen($host)) {
            return null;
        }
        $host = strtr($host, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        if (strpbrk($host, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F #%/:<>?@[\\]^|\x7F") !== false) {
            return null;
        }
        if (!self::endsInANumber($host)) {
            return $host;
        }
        $address = self::parseIpv4($host);
        return $address === null ? null : implode('.', [($address >> 24) & 0xFF, ($address >> 16) & 0xFF, ($address >> 8) & 0xFF, $address & 0xFF]);
    }

    private static function endsInANumber(string $host): bool
    {
        $parts = explode('.', $host);
        if (end($parts) === '') {
            if (count($parts) === 1) {
                return false;
            }
            array_pop($parts);
        }
        $last = (string) end($parts);
        if ($last !== '' && strspn($last, self::DIGITS) === strlen($last)) {
            return true;
        }
        return self::parseIpv4Number($last) !== null;
    }

    private static function parseIpv4(string $host): ?int
    {
        $parts = explode('.', $host);
        if (end($parts) === '' && count($parts) > 1) {
            array_pop($parts);
        }
        if (count($parts) > 4) {
            return null;
        }
        $numbers = [];
        foreach ($parts as $part) {
            $number = self::parseIpv4Number($part);
            if ($number === null) {
                return null;
            }
            $numbers[] = $number;
        }
        $last = array_pop($numbers);
        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }
        }
        if ($last >= 256 ** (4 - count($numbers))) {
            return null;
        }
        $address = $last;
        foreach ($numbers as $i => $number) {
            $address += $number * 256 ** (3 - $i);
        }
        return (int) $address;
    }

    private static function parseIpv4Number(string $part): ?int
    {
        if ($part === '') {
            return null;
        }
        $radix = 10;
        if (strlen($part) >= 2 && ($part[1] === 'x' || $part[1] === 'X') && $part[0] === '0') {
            $radix = 16;
            $part = substr($part, 2);
        } elseif (strlen($part) >= 2 && $part[0] === '0') {
            $radix = 8;
            $part = substr($part, 1);
        }
        if ($part === '') {
            return 0;
        }
        $alphabet = substr('0123456789abcdef', 0, $radix);
        if (strspn(strtolower($part), $alphabet) !== strlen($part)) {
            return null;
        }
        $part = ltrim($part, '0');
        // Anything past 2^32 fails the caller's range check either way.
        if (strlen($part) > 12) {
            return PHP_INT_MAX;
        }
        return $part === '' ? 0 : (int) base_convert($part, $radix, 10);
    }

    /** The WHATWG IPv6 serialisation: lowercase hex, the longest run of 2+ zero pieces as `::`. */
    private static function serializeIpv6(string $packed): string
    {
        $pieces = array_values(unpack('n8', $packed));
        $bestStart = -1;
        $bestLength = 1;
        for ($i = 0; $i < 8;) {
            if ($pieces[$i] !== 0) {
                $i++;
                continue;
            }
            $start = $i;
            while ($i < 8 && $pieces[$i] === 0) {
                $i++;
            }
            if ($i - $start > $bestLength) {
                $bestStart = $start;
                $bestLength = $i - $start;
            }
        }
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === $bestStart) {
                $out .= $i === 0 ? '::' : ':';
                $i += $bestLength - 1;
                continue;
            }
            $out .= dechex($pieces[$i]) . ($i < 7 ? ':' : '');
        }
        return $out;
    }
}

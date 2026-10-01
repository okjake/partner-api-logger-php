<?php

// References logger-spec PII redaction contract (PAPI-1102).
// Verifies the public `RedactPii::redact` / `PartnerApi\Logger\redactPII`
// helper exported by the package. Covers string PII, headers, JSON body,
// query string, deeply nested arrays, primitives, and option overrides.

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\RedactPii;
use PartnerApi\Logger\RedactPiiFoldTable;
use function PartnerApi\Logger\redactPII;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RedactPiiTest extends TestCase
{
    /**
     * PAPI-4367 unit helper contract: mirror the canonical database matcher's
     * whole segments, prefix-tolerant runs, head nouns and exact +s plurals.
     * Exercise the public API with inert values so value regexes cannot hide
     * a missing key rule. Both redaction and readable operational names matter.
     */
    #[DataProvider('sensitiveFieldNames')]
    public function testCanonicalSensitiveFieldNames(string $key, string $marker): void
    {
        $this->assertSame([$key => $marker], RedactPii::redact([$key => 'qfprobevalue']));
    }

    public static function sensitiveFieldNames(): array
    {
        return [
            ['clientSecret', '[SECRET_REDACTED]'],
            ['sessionKey', '[KEY_REDACTED]'],
            ['session_key', '[KEY_REDACTED]'],
            ['userPassword', '[PASSWORD_REDACTED]'],
            ['refreshToken', '[TOKEN_REDACTED]'],
            ['privateKey', '[KEY_REDACTED]'],
            ['xApiKey', '[KEY_REDACTED]'],
            ['accessToken', '[TOKEN_REDACTED]'],
            ['secrets', '[SECRET_REDACTED]'],
            ['passwords', '[PASSWORD_REDACTED]'],
            ['apikeys', '[KEY_REDACTED]'],
            ['tokens', '[TOKEN_REDACTED]'],
            ['cookies', '[COOKIE_REDACTED]'],
            ['socialSecurity', '[SENSITIVE_DATA_REDACTED]'],
            ['creditCardNumber', '[CARD_REDACTED]'],
            ['sessionId', '[SESSION_REDACTED]'],
            ['session_id', '[SESSION_REDACTED]'],
            ['api_key', '[KEY_REDACTED]'],
            ['apikey', '[KEY_REDACTED]'],
            ['user_token', '[TOKEN_REDACTED]'],
            ['password', '[PASSWORD_REDACTED]'],
            ['secret', '[SECRET_REDACTED]'],
            ['x-api-key', '[KEY_REDACTED]'],
            ['x-auth-token', '[TOKEN_REDACTED]'],
            ['cvv', '[CARD_REDACTED]'],
            ['cookie', '[COOKIE_REDACTED]'],
            ['secretKey', '[KEY_REDACTED]'],
            ['passwordHash', '[PASSWORD_REDACTED]'],
            ['api_key_id', '[KEY_REDACTED]'],
            ['api_keys', '[KEY_REDACTED]'],
            ['accessTokens', '[TOKEN_REDACTED]'],
            ['session_ids', '[SESSION_REDACTED]'],
            ['creditCards', '[CARD_REDACTED]'],
            ['private_keys', '[KEY_REDACTED]'],
            ['credit_cardholder', '[CARD_REDACTED]'],
            ['userPin', '[SENSITIVE_DATA_REDACTED]'],
            ['serverCert', '[SENSITIVE_DATA_REDACTED]'],
            ['tlsCertificate', '[SENSITIVE_DATA_REDACTED]'],
            ['passwd', '[SENSITIVE_DATA_REDACTED]'],
            ['oauthPwd', '[PASSWORD_REDACTED]'],
            ['authBearer', '[SENSITIVE_DATA_REDACTED]'],
            ['userSsn', '[SENSITIVE_DATA_REDACTED]'],
            ['cardCvc', '[CARD_REDACTED]'],
            ['publickey', '[KEY_REDACTED]'],
            ['APIKeyId', '[KEY_REDACTED]'],
            ['HTTPCookie', '[COOKIE_REDACTED]'],
            ['session.id', '[SESSION_REDACTED]'],
            ['auth.clientSecret', '[SECRET_REDACTED]'],
            ['_sessionId', '[SESSION_REDACTED]'],
            ['token_', '[TOKEN_REDACTED]'],
            ['SESSIONID', '[SESSION_REDACTED]'],
            ['cvc', '[SENSITIVE_DATA_REDACTED]'],
            ['access_tokens', '[TOKEN_REDACTED]'],
            ['userSessions', '[SESSION_REDACTED]'],
            ['serverCerts', '[SENSITIVE_DATA_REDACTED]'],
            ['userPins', '[SENSITIVE_DATA_REDACTED]'],
            // Canonical JavaScript lowercase introduces ASCII from these folds.
            ['apiKey', '[KEY_REDACTED]'],
            ['apiKeyId', '[KEY_REDACTED]'],
            ['cooKie', '[COOKIE_REDACTED]'],
            ['APİ_KEY', '[KEY_REDACTED]'],
            ['apİKeys', '[KEY_REDACTED]'],
            ['cooKies', '[COOKIE_REDACTED]'],
        ];
    }

    #[DataProvider('readableFieldNames')]
    public function testCanonicalReadableFieldNames(string $key): void
    {
        $this->assertSame([$key => 'qfprobevalue'], RedactPii::redact([$key => 'qfprobevalue']));
    }

    public static function readableFieldNames(): array
    {
        return [
            ['sessionDuration'],
            ['tokenCount'],
            ['tokenExpiresAt'],
            ['sessionStartedAt'],
            ['keyboardLayout'],
            ['pinned'],
            ['certainty'],
            ['secretary'],
            ['cacheKey'],
            ['sortKey'],
            ['partitionKey'],
            ['idempotencyKey'],
            ['reservationId'],
            ['cardId'],
            ['bookingReference'],
            ['certExpiryDays'],
            ['pinPosition'],
            ['session.duration'],
            [''],
            ['___'],
            ['xapi_key'],
            ['secretsauce'],
            ['cookieValue'],
            ['tokensCount'],
            // Fold after case boundaries; do not add compatibility normalization.
            ['fooKPassword'],
            ['fooKToken'],
            ['apiＫey'],
            ['ſecret'],
            ['APİ_X_KEY'],
            ['sessionKey'],
        ];
    }

    public function testEquivalentSpellingConventions(): void
    {
        foreach ([
            ['clientSecret', 'client_secret', 'client-secret'],
            ['sessionKey', 'session_key', 'session-key'],
            ['userPassword', 'user_password', 'user-password'],
            ['sessionDuration', 'session_duration', 'session-duration'],
        ] as [$camel, $snake, $kebab]) {
            $expected = RedactPii::redact([$camel => 'qfprobevalue'])[$camel];
            $this->assertSame($expected, RedactPii::redact([$snake => 'qfprobevalue'])[$snake]);
            $this->assertSame($expected, RedactPii::redact([$kebab => 'qfprobevalue'])[$kebab]);
        }
    }

    public function testNestedKeyPolicySurvivesStringOptionsAndDropMode(): void
    {
        $input = ['auth' => [
            'clientSecret' => ['nested' => 'qfprobevalue'],
            'sessionKey' => null,
            'passwordHash' => false,
            'tokenCount' => 7,
            'notes' => 'a@example.com',
        ]];
        $options = ['redactEmails' => false, 'redactTokens' => false,
            'redactPasswords' => false, 'redactApiKeys' => false];
        $this->assertSame(['auth' => [
            'clientSecret' => '[SECRET_REDACTED]',
            'sessionKey' => '[KEY_REDACTED]',
            'passwordHash' => '[PASSWORD_REDACTED]',
            'tokenCount' => 7,
            'notes' => 'a@example.com',
        ]], redactPII($input, $options));
        $this->assertSame(['auth' => ['tokenCount' => 7, 'notes' => 'a@example.com']],
            RedactPii::redact($input, array_merge($options, ['preserveStructure' => false])));
        $this->assertSame(['nested' => 'qfprobevalue'], $input['auth']['clientSecret']);
        $this->assertNull($input['auth']['sessionKey']);
    }

    public function testLongAcronymBoundaryDoesNotExhaustPcreBacktracking(): void
    {
        // Fixed-width lookahead scans this adversarial key once. The old
        // tempting ([A-Z]+)([A-Z][a-z]) split exhausts this small budget.
        $previous = ini_set('pcre.backtrack_limit', '10000');
        try {
            $key = str_repeat('A', 50000) . '_HTTPCookie';
            $this->assertSame('[COOKIE_REDACTED]', RedactPii::redact([$key => 'qfprobevalue'])[$key]);
            $this->assertSame(PREG_NO_ERROR, preg_last_error());
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }

    public function testProceduralAliasReachableFromPackageNamespace(): void
    {
        $this->assertSame(
            'contact [EMAIL_REDACTED]',
            redactPII('contact alice@example.com'),
        );
    }

    public function testProceduralAliasIsAutoloadedNotClassSideEffect(): void
    {
        // Regression: PHP does not autoload functions, so PSR-4 alone left
        // `PartnerApi\Logger\redactPII` undefined until `RedactPii` happened
        // to be loaded for some other reason. With `autoload.files` wired up
        // the function must exist purely from Composer's autoloader, without
        // anything needing to reference the class first.
        $this->assertTrue(
            function_exists('PartnerApi\\Logger\\redactPII'),
            'redactPII() must be registered via composer autoload.files so the '
            . 'documented `use function` import works without first loading the class.',
        );
    }

    public function testStaticHelperRedactsEmails(): void
    {
        $this->assertSame(
            'contact [EMAIL_REDACTED] for details',
            RedactPii::redact('contact alice@example.com for details'),
        );
    }

    public function testRedactsJwts(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc123signaturepart';
        $this->assertStringContainsString(
            '[JWT_TOKEN_REDACTED]',
            (string) RedactPii::redact("auth=$jwt"),
        );
    }

    public function testRedactsBearerTokens(): void
    {
        $this->assertSame(
            'Authorization: Bearer [TOKEN_REDACTED]',
            RedactPii::redact('Authorization: Bearer abc123token'),
        );
    }

    public function testRedactsDottedBearerTokensInFull(): void
    {
        // Regression: the prior character class stopped at the first `.`,
        // leaking the tail (`.def.ghi`) into sanitized output.
        $this->assertSame(
            'Authorization: Bearer [TOKEN_REDACTED]',
            RedactPii::redact('Authorization: Bearer abc.def.ghi'),
        );
    }

    public function testRedactsNamedApiKeyPrefixes(): void
    {
        $this->assertStringContainsString(
            '[API_KEY_REDACTED]',
            (string) RedactPii::redact('use sk-livetestkey1234567890 for billing'),
        );
    }

    public function testRedactsCreditCards(): void
    {
        $this->assertSame(
            'card [CARD_REDACTED]',
            RedactPii::redact('card 4111-1111-1111-1111'),
        );
    }

    public function testRedactsPhoneNumbersFormatted(): void
    {
        $this->assertStringContainsString(
            '[PHONE_REDACTED]',
            (string) RedactPii::redact('call +15551234567'),
        );
    }

    public function testRedactsIpv4(): void
    {
        $this->assertSame(
            'client [IP_REDACTED] connected',
            RedactPii::redact('client 192.168.1.42 connected'),
        );
    }

    public function testStripsUrlQueryStrings(): void
    {
        $this->assertSame(
            'see https://example.com/path?[QUERY_REDACTED]',
            RedactPii::redact('see https://example.com/path?token=abc&u=1'),
        );
    }

    public function testRedactsPasswordAssignmentsInQueryString(): void
    {
        $this->assertStringContainsString(
            'password=[PASSWORD_REDACTED]',
            (string) RedactPii::redact('login?password=hunter2&user=alice'),
        );
    }

    public function testReturnsStringUnchangedWhenNoPii(): void
    {
        $this->assertSame('hello world', RedactPii::redact('hello world'));
    }

    public function testRedactsSensitiveHeaderKeysRegardlessOfCasing(): void
    {
        $headers = [
            'Authorization' => 'Bearer secret-token',
            'X-Api-Key' => 'sk-livetestkey1234567890',
            'Cookie' => 'session=abc',
            'Content-Type' => 'application/json',
        ];
        $redacted = RedactPii::redact($headers);
        $this->assertSame('Bearer [TOKEN_REDACTED]', $redacted['Authorization']);
        $this->assertSame('[KEY_REDACTED]', $redacted['X-Api-Key']);
        $this->assertSame('[COOKIE_REDACTED]', $redacted['Cookie']);
        $this->assertSame('application/json', $redacted['Content-Type']);
    }

    public function testRedactsSensitiveJsonBodyKeys(): void
    {
        $body = [
            'email' => 'alice@example.com',
            'password' => 'hunter2',
            'firstName' => 'Alice',
        ];
        $redacted = RedactPii::redact($body);
        $this->assertSame('[EMAIL_REDACTED]', $redacted['email']);
        $this->assertSame('[PASSWORD_REDACTED]', $redacted['password']);
        $this->assertSame('Alice', $redacted['firstName']);
    }

    public function testRedactsQueryParamArray(): void
    {
        $queryParams = [
            'token' => 'abc-secret-xyz',
            'user' => 'alice',
            'api_key' => 'sk-livetestkey1234567890',
        ];
        $redacted = RedactPii::redact($queryParams);
        $this->assertSame('[TOKEN_REDACTED]', $redacted['token']);
        $this->assertSame('[KEY_REDACTED]', $redacted['api_key']);
        $this->assertSame('alice', $redacted['user']);
    }

    public function testRedactsDeeplyNestedArrays(): void
    {
        $payload = [
            'users' => [
                [
                    'id' => 'u_1',
                    'email' => 'a@b.com',
                    'credentials' => [
                        'password' => 'hunter2',
                        'refresh_token' => 'rt_xyz',
                    ],
                ],
            ],
            'meta' => [
                'requestIp' => '10.0.0.1',
                'contact' => [
                    'phone' => '+15551234567',
                    'notes' => 'reach me at e@f.com',
                ],
            ],
        ];
        $redacted = RedactPii::redact($payload);
        $this->assertSame('[EMAIL_REDACTED]', $redacted['users'][0]['email']);
        $this->assertSame('[PASSWORD_REDACTED]', $redacted['users'][0]['credentials']['password']);
        $this->assertSame('[TOKEN_REDACTED]', $redacted['users'][0]['credentials']['refresh_token']);
        $this->assertSame('[IP_REDACTED]', $redacted['meta']['requestIp']);
        $this->assertSame('[PHONE_REDACTED]', $redacted['meta']['contact']['phone']);
        $this->assertSame('reach me at [EMAIL_REDACTED]', $redacted['meta']['contact']['notes']);
        // Original untouched
        $this->assertSame('a@b.com', $payload['users'][0]['email']);
    }

    public function testReturnsNullUnchanged(): void
    {
        $this->assertNull(RedactPii::redact(null));
    }

    public function testReturnsEmptyStringUnchanged(): void
    {
        $this->assertSame('', RedactPii::redact(''));
    }

    public function testRedactEmailsFalseOptionOverride(): void
    {
        $this->assertSame(
            'contact alice@example.com',
            RedactPii::redact('contact alice@example.com', ['redactEmails' => false]),
        );
    }

    public function testPreserveStructureFalseDropsSensitiveKeys(): void
    {
        $redacted = RedactPii::redact(
            ['username' => 'alice', 'password' => 'hunter2'],
            ['preserveStructure' => false],
        );
        $this->assertSame(['username' => 'alice'], $redacted);
    }

    public function testOptInUuidRedaction(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $this->assertSame("id=$uuid", RedactPii::redact("id=$uuid"));
        $this->assertSame(
            'id=[ID]',
            RedactPii::redact("id=$uuid", ['redactUuids' => true]),
        );
    }

    /*
     * FLT-1191 — what the PHP port has to get right that the shared corpus
     * does not reach: strings that are not valid UTF-8, integer keys, the
     * Unicode spaces JavaScript's `\s` takes, the compatibility fold, the
     * phone context window counted in JavaScript characters, and the URL
     * parse `new URL()` runs. Every expected value below is what
     * `@partner-api/logger`'s `redactPII` returns for the same input, except
     * where a comment says otherwise.
     */

    public function testInvalidUtf8IsRedactedNotFailed(): void
    {
        // Under the `u` modifier every rule would fail on these bytes.
        $this->assertSame(
            "caf\xE9 [EMAIL_REDACTED] \xFF [PHONE_REDACTED] \xC2",
            RedactPii::redact("caf\xE9 jane@realco-invented.test \xFF +44 20 7946 0000 \xC2"),
        );
    }

    public function testAnIntegerKeyIsRedactedLikeAStringKey(): void
    {
        // PHP turns the JSON key "4111111111111111" into an int key.
        $this->assertSame(
            ['[CARD_REDACTED]' => 'a', 'n' => [7, '[EMAIL_REDACTED]']],
            RedactPii::redact([4111111111111111 => 'a', 'n' => [7, 'jane@realco-invented.test']]),
        );
        $this->assertSame([0 => '[EMAIL_REDACTED]', 1 => 2], RedactPii::redact(['jane@realco-invented.test', 2]));
    }

    #[DataProvider('javascriptSpaces')]
    public function testUnicodeSpacesCountAsJavaScriptWhitespace(string $input, string $expected): void
    {
        $this->assertSame($expected, RedactPii::redact($input));
    }

    public static function javascriptSpaces(): array
    {
        return [
            'Bearer then NBSP' => ["Bearer\u{A0}abc.def", 'Bearer [TOKEN_REDACTED]'],
            'Bearer then line separator' => ["Bearer\u{2028}abc.def", 'Bearer [TOKEN_REDACTED]'],
            'a password ends at NBSP' => ["password=hunter2\u{A0}rest", "password=[PASSWORD_REDACTED]\u{A0}rest"],
            'a card grouped by NBSP' => ["card 4111\u{A0}1111\u{A0}1111\u{A0}1111", 'card [CARD_REDACTED]'],
            'a URL ends at an ideographic space' => ["https://h.test/p?q=1\u{3000}next", "https://h.test/p?[QUERY_REDACTED]\u{3000}next"],
        ];
    }

    #[DataProvider('foldedPhones')]
    public function testPhoneRuleMatchesOnTheCompatibilityFold(string $input, string $expected): void
    {
        $this->assertSame($expected, RedactPii::redact($input));
    }

    public static function foldedPhones(): array
    {
        return [
            'mathematical digits' => ["tel +44 \u{1D7D0}\u{1D7CE} 7946 0000 end", 'tel [PHONE_REDACTED] end'],
            'zero-width space and soft hyphen' => ["tel +44\u{200B}20\u{AD}7946 0000 end", 'tel [PHONE_REDACTED] end'],
            'minus signs' => ["tel +44\u{2212}20\u{2212}7946\u{2212}0000 end", 'tel [PHONE_REDACTED] end'],
            'the telephone sign is the word tel' => ["\u{2121} 4155550132", "\u{2121} [PHONE_REDACTED]"],
            'a quoted-printable soft break' => ["line=\nphone +44 20 7946 0000", "line=\nphone [PHONE_REDACTED]"],
        ];
    }

    public function testTheFoldTableFoldsEveryUnicodeSpaceDashAndAtLookalike(): void
    {
        foreach (["\u{A0}", "\u{1680}", "\u{2000}", "\u{2005}", "\u{200A}", "\u{202F}", "\u{205F}", "\u{3000}"] as $space) {
            $this->assertSame(' ', RedactPiiFoldTable::FOLD[$space] ?? null, bin2hex($space));
        }
        foreach (["\u{2010}", "\u{2013}", "\u{2014}", "\u{2212}", "\u{FE63}"] as $dash) {
            $this->assertSame('-', RedactPiiFoldTable::FOLD[$dash] ?? null, bin2hex($dash));
        }
        foreach (["\u{FE6B}", "\u{24B6}", "\u{24D0}", "\u{249C}", "\u{1F110}", "\u{1F130}", "\u{1F150}", "\u{1F170}"] as $at) {
            $this->assertSame('@', RedactPiiFoldTable::FOLD[$at] ?? null, bin2hex($at));
        }
        foreach (["\u{AD}", "\u{200B}", "\u{FEFF}"] as $invisible) {
            $this->assertSame('', RedactPiiFoldTable::FOLD[$invisible] ?? null, bin2hex($invisible));
        }
        $this->assertSame('2', RedactPiiFoldTable::FOLD["\u{B2}"]);
        $this->assertSame('1', RedactPiiFoldTable::FOLD["\u{2460}"]);
        $this->assertArrayNotHasKey("\u{E9}", RedactPiiFoldTable::FOLD);
    }

    /**
     * The 15-character window around ten bare digits is counted in UTF-16
     * code units, as JavaScript counts it: nine CJK characters (27 bytes) and
     * a space still reach `phone`, ten do not; an emoji counts twice.
     */
    #[DataProvider('contextWindows')]
    public function testPhoneContextWindowCountsJavaScriptCharacters(string $between, bool $redacted): void
    {
        $input = "phone{$between} 4155550132";
        $this->assertSame(
            $redacted ? "phone{$between} [PHONE_REDACTED]" : $input,
            RedactPii::redact($input),
        );
    }

    public static function contextWindows(): array
    {
        return [
            'nine CJK characters' => [str_repeat("\u{4E2D}", 9), true],
            'ten CJK characters' => [str_repeat("\u{4E2D}", 10), false],
            'four emoji' => [str_repeat("\u{1F600}", 4), true],
            'five emoji' => [str_repeat("\u{1F600}", 5), false],
        ];
    }

    /**
     * The query rule rebuilds the URL as JavaScript's
     * `${url.protocol}//${url.host}${url.pathname}` does.
     */
    #[DataProvider('urls')]
    public function testUrlWithoutItsQueryIsTheWhatwgSerialisation(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            RedactPii::redact($input, ['redactEmails' => false, 'redactIpAddresses' => false]),
        );
    }

    public static function urls(): array
    {
        return [
            'userinfo, default port, case, dot segments, backslash and fragment' => [
                'see https://u:p@H.Example.TEST:443/a/./b/../c\\d?x=1#frag',
                'see https://h.example.test/a/c/d?[QUERY_REDACTED]',
            ],
            'an IPv6 host is compressed' => ['see https://[2001:DB8:0:0:0:0:0:1]/p?q=1', 'see https://[2001:db8::1]/p?[QUERY_REDACTED]'],
            'an IPv4-mapped IPv6 host is written in hex' => ['see https://[::ffff:1.2.3.4]/p?q=1', 'see https://[::ffff:102:304]/p?[QUERY_REDACTED]'],
            'a hex IPv4 host is normalised' => ['see https://0x7f.1/p?q=1', 'see https://127.0.0.1/p?[QUERY_REDACTED]'],
            'a percent-encoded host is decoded' => ['see https://%41pi.test/p?q=1', 'see https://api.test/p?[QUERY_REDACTED]'],
            'a path is percent-encoded' => ["see https://h.test/a\"c<d>`{}\u{E9}?q=1", 'see https://h.test/a%22c%3Cd%3E%60%7B%7D%C3%A9?[QUERY_REDACTED]'],
            'encoded dot segments resolve' => ['see https://h.test/%2e%2E/x/%2e?q=1', 'see https://h.test/x/?[QUERY_REDACTED]'],
            'extra slashes before the host' => ['see https:///h.test?q=1', 'see https://h.test/?[QUERY_REDACTED]'],
            'a padded default port is still default' => ['see http://h.test:0080/?q=1', 'see http://h.test/?[QUERY_REDACTED]'],
            'a padded port is unpadded' => ['see https://h.test:0080/?q=1', 'see https://h.test:80/?[QUERY_REDACTED]'],
            'an empty port' => ['see https://h.test:/p?q=1', 'see https://h.test/p?[QUERY_REDACTED]'],
            'an empty query is kept' => ['see https://h.test/p?#frag', 'see https://h.test/p?#frag'],
            'a bare question mark is kept' => ['see https://h.test/p?', 'see https://h.test/p?'],
            'an out-of-range IPv4 host does not parse' => ['see https://1.2.3.256/p?q=1', 'see https://1.2.3.256/p?[QUERY_REDACTED]'],
            'an out-of-range port does not parse' => ['see https://h.test:99999/p?q=1', 'see https://h.test:99999/p?[QUERY_REDACTED]'],
            'an empty host does not parse' => ['see https://user@/p?q=1', 'see https://user@/p?[QUERY_REDACTED]'],
            // JavaScript converts a non-ASCII host to punycode
            // (`xn--caf-dma.test`); without ext-intl PHP cannot, so the URL
            // takes the unparseable branch. The query goes either way.
            'a non-ASCII host keeps its spelling' => ["see https://caf\u{E9}.test/p?q=1", "see https://caf\u{E9}.test/p?[QUERY_REDACTED]"],
        ];
    }
}

<?php

// References logger-spec PII redaction contract (PAPI-1102).
// Verifies the public `RedactPii::redact` / `PartnerApi\Logger\redactPII`
// helper exported by the package. Covers string PII, headers, JSON body,
// query string, deeply nested arrays, primitives, and option overrides.

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\RedactPii;
use function PartnerApi\Logger\redactPII;
use PHPUnit\Framework\TestCase;

class RedactPiiTest extends TestCase
{
    public function testProceduralAliasReachableFromPackageNamespace(): void
    {
        $this->assertSame(
            'contact [EMAIL_REDACTED]',
            redactPII('contact alice@example.com'),
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
}

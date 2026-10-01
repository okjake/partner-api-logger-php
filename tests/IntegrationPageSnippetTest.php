<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Runs the integration page's PHP upstream-trail snippet against the real SDK
 * (FLT-1301), so the copy a tenant pastes is proven to put both calls on the
 * response line — not just to have the right shape. The PHP twin of
 * `upstream-trail-snippet.run.test.ts`, which does the same for JavaScript.
 *
 * The snippet is read from the web module that renders it. It is split at its
 * "Inside a handler" comment: the setup half runs once, the handler half runs
 * inside a request, in the same scope, as a tenant's code would. Everything
 * the snippet leaves free (`$logger`, `$apiKey`, `$body`, `$correlationId`) is
 * supplied here. ONE token is substituted: `HandlerStack::create()` gets a
 * MockHandler for Stripe, because a test must not reach api.stripe.com.
 */
class IntegrationPageSnippetTest extends TestCase
{
    use CapturesLogs;

    private const KEY = 'app-key-1';

    private const WEB_MODULE = '/../../../apps/web/src/app/(tenant-admin)/(with-navigation)/~subdomain/[subdomain]/admin/integration/upstream-trail-snippet.ts';

    public function testTheSnippetShipsBothCallsInOrderOnTheOutgoingResponseLine(): void
    {
        $snippet = $this->snippet();
        $at = strpos($snippet, '// Inside a handler');
        $this->assertNotFalse($at);
        $setup = substr($snippet, 0, $at);
        $handler = substr($snippet, $at);

        $vendor = new MockHandler([new Response(200, ['request-id' => 'req_stripe_1'], '{}')]);
        $setup = str_replace('HandlerStack::create()', 'HandlerStack::create($vendor)', $setup, $substituted);
        $this->assertSame(1, $substituted, 'the one substitution this test makes');

        $logger = $this->logger();
        $apiKey = self::KEY;
        $body = ['amount' => 100];

        eval($setup);
        $correlationId = $logger->logRequest($apiKey, ['method' => 'POST', 'path' => '/orders']);
        eval($handler);
        $logger->flush();

        $this->assertSame('/v1/charges', $vendor->getLastRequest()->getUri()->getPath());
        $this->assertSame([
            [
                'name' => 'stripe',
                'method' => 'POST',
                'url' => 'https://api.stripe.com/v1/charges',
                'status' => 200,
                'durationMs' => 0,
                'requestId' => 'req_stripe_1',
            ],
            [
                'name' => 'pricing',
                'method' => 'GET',
                'url' => 'https://pricing.internal/quote',
                'status' => 200,
                'durationMs' => 41,
            ],
        ], $this->responses()[0]['upstream']);
        $this->assertSame(self::KEY, $this->captured[0]['options']['headers']['x-api-key']);
        // Nothing was dropped or misrouted on the way.
        $this->assertSame([], $this->reported);
    }

    /** The snippet's PHP source, unescaped from its TypeScript template literal. */
    private function snippet(): string
    {
        $monorepoApps = __DIR__ . '/../../../apps';
        if (!is_dir($monorepoApps)) {
            // The published Composer package carries no web app.
            $this->markTestSkipped('outside the partner-api monorepo');
        }

        $module = file_get_contents(__DIR__ . self::WEB_MODULE);
        $this->assertIsString($module, 'the web module that renders the snippet has moved');
        $found = preg_match('/export const phpUpstreamSnippet = `((?:\\\\.|[^`\\\\])*)`;/s', $module, $match);
        $this->assertSame(1, $found, 'phpUpstreamSnippet is no longer a plain template literal');
        $this->assertStringNotContainsString('${', $match[1], 'an interpolation this test cannot evaluate');

        return strtr($match[1], ['\\\\' => '\\', '\\`' => '`', '\\$' => '$']);
    }
}

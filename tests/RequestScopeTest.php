<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\Logger;
use PartnerApi\Logger\LoggerErrorEvent;
use PartnerApi\Logger\ScopedLogger;
use PHPUnit\Framework\TestCase;

/**
 * Per-request context isolation (PAPI-5336) — PHP parity, FLT-1301.
 *
 * `packages/logger-spec/spec.md` § "Per-request context isolation": PHP-FPM is
 * conformant with no scope API, but a long-running worker serves many
 * requests from one logger and needs `runWithContext()` / `child()`. The
 * fixtures drive one call at a time and cannot grade this, so — as the spec
 * asks of every implementation — this file carries the interleaving tests.
 * The TypeScript equivalent is `packages/logger/src/request-scope.spec.ts`.
 */
class RequestScopeTest extends TestCase
{
    use CapturesLogs;

    private const KEY = 'app-key';

    protected function setUp(): void
    {
        $this->captured = [];
        $this->reported = [];
        $this->now = 0.0;
    }

    // ------------------------------------------------------------- isolation

    public function testTwoChildrenInterleavedNeverStampEachOthersContext(): void
    {
        $logger = $this->logger();
        $a = $logger->child(['partnerId' => 'partner-a']);
        $b = $logger->child(['partnerId' => 'partner-b']);

        // Interleaved the way a coroutine server would run two requests.
        $corrA = $a->logRequest(self::KEY, ['method' => 'GET', 'path' => '/a', 'headers' => ['x-correlation-id' => 'corr-a']]);
        $b->setContext(['direction' => 'outbound', 'upstreamIntegration' => 'stripe', 'upstreamBaseUrl' => 'https://api.stripe.com']);
        $corrB = $b->logRequest(self::KEY, ['method' => 'POST', 'path' => '/b', 'headers' => ['x-correlation-id' => 'corr-b', 'x-request-id' => 'req-b']]);
        $a->info(self::KEY, 'a working');
        $b->info(self::KEY, 'b working');
        $a->logResponse(self::KEY, ['statusCode' => 200, 'duration' => 1, 'correlationId' => $corrA]);
        $b->logResponse(self::KEY, ['statusCode' => 201, 'duration' => 2, 'correlationId' => $corrB]);
        $logger->flush();

        foreach ($this->lines() as $line) {
            if ($line['__labels']['partnerId'] === 'partner-a') {
                $this->assertSame('partner-a', $line['partnerId']);
                $this->assertSame('corr-a', $line['correlation_id']);
                $this->assertSame('/a', $line['path']);
                $this->assertArrayNotHasKey('request_id', $line);
                $this->assertArrayNotHasKey('direction', $line['__labels']);
            } else {
                $this->assertSame('partner-b', $line['__labels']['partnerId']);
                $this->assertSame('partner-b', $line['partnerId']);
                $this->assertSame('corr-b', $line['correlation_id']);
                $this->assertSame('req-b', $line['request_id']);
                $this->assertSame('outbound', $line['__labels']['direction']);
            }
        }
        $this->assertCount(6, $this->lines());

        // Upstream attribution is per POST, and only B's POSTs carry it.
        foreach ($this->captured as $request) {
            $body = $request['options']['json'];
            if ($body['labels']['partnerId'] === 'partner-a') {
                $this->assertArrayNotHasKey('upstream_integration', $body);
            } else {
                $this->assertSame('stripe', $body['upstream_integration']);
                $this->assertSame('https://api.stripe.com', $body['upstream_base_url']);
            }
        }
    }

    public function testSequentialRequestsOnOneLongLivedLoggerStartClean(): void
    {
        $logger = $this->logger();

        $logger->runWithContext(['partnerId' => 'partner-1'], function () use ($logger): void {
            $logger->setContext(['upstreamIntegration' => 'stripe']);
            $this->exchange($logger, 'corr-1');
        });
        $logger->runWithContext(['partnerId' => 'partner-2'], function () use ($logger): void {
            $logger->info(self::KEY, 'second request');
        });
        $logger->flush();

        $second = array_values(array_filter($this->lines(), fn ($l) => $l['message'] === 'second request'))[0];
        $this->assertSame('partner-2', $second['partnerId']);
        // Nothing the first request set survives into the second.
        $this->assertArrayNotHasKey('correlation_id', $second);
        $this->assertArrayNotHasKey('status_code', $second);
        $this->assertArrayNotHasKey('path', $second);
        $lastPost = end($this->captured)['options']['json'];
        $this->assertArrayNotHasKey('upstream_integration', $lastPost);
    }

    public function testScopedEntriesGroupCorrectlyInOneDrain(): void
    {
        $logger = $this->logger();
        $logger->runWithContext(['partnerId' => 'p-1'], fn () => $logger->info(self::KEY, 'one'));
        $logger->runWithContext(['partnerId' => 'p-2'], fn () => $logger->info(self::KEY, 'two'));
        $logger->runWithContext(['partnerId' => 'p-1'], fn () => $logger->info(self::KEY, 'three'));

        $this->assertSame([], $this->captured, 'scopes change nothing about when entries are sent');
        $logger->flush();

        $this->assertCount(2, $this->captured, 'one POST per distinct labels shape, in one drain');
        $this->assertSame(['level' => 'info', 'partnerId' => 'p-1'], $this->captured[0]['options']['json']['labels']);
        $this->assertCount(2, $this->captured[0]['options']['json']['entries']);
        $this->assertSame(['level' => 'info', 'partnerId' => 'p-2'], $this->captured[1]['options']['json']['labels']);
    }

    // ------------------------------------------------------------ inheritance

    public function testAScopeStartsAsACopyOfTheContextWhereItWasOpened(): void
    {
        $logger = $this->logger();
        $logger->setContext(['partnerId' => 'outer', 'requestId' => 'req-outer']);

        $logger->runWithContext(['requestId' => 'req-inner'], function () use ($logger): void {
            $logger->info(self::KEY, 'inner');
            $logger->setContext(['partnerId' => 'changed-inside']);
        });
        $logger->info(self::KEY, 'after');
        $logger->flush();

        [$inner, $after] = $this->lines();
        $this->assertSame('outer', $inner['partnerId'], 'inherited');
        $this->assertSame('req-inner', $inner['request_id'], 'merged with the fields supplied when opening it');
        $this->assertSame('outer', $after['partnerId'], 'nothing written inside a scope is visible outside it');
        $this->assertSame('req-outer', $after['request_id']);
    }

    public function testScopesNestAndEachRestoresItsParent(): void
    {
        $logger = $this->logger();

        $result = $logger->runWithContext(['partnerId' => 'outer'], function () use ($logger): string {
            $logger->info(self::KEY, 'outer-1');
            $inner = $logger->runWithContext(['requestId' => 'inner-req'], function () use ($logger): string {
                $logger->setContext(['partnerId' => 'inner']);
                $logger->info(self::KEY, 'inner');

                return 'inner-result';
            });
            $logger->info(self::KEY, 'outer-2');

            return "outer({$inner})";
        });
        $logger->info(self::KEY, 'root');
        $logger->flush();

        $this->assertSame('outer(inner-result)', $result, 'runWithContext returns what fn returns');
        $byMessage = [];
        foreach ($this->lines() as $line) {
            $byMessage[$line['message']] = $line;
        }
        $this->assertSame('outer', $byMessage['outer-1']['partnerId']);
        $this->assertSame('inner', $byMessage['inner']['partnerId']);
        $this->assertSame('inner-req', $byMessage['inner']['request_id']);
        $this->assertSame('outer', $byMessage['outer-2']['partnerId']);
        $this->assertArrayNotHasKey('request_id', $byMessage['outer-2']);
        $this->assertArrayNotHasKey('partnerId', $byMessage['root']);
    }

    public function testTheParentScopeIsRestoredWhenFnThrows(): void
    {
        $logger = $this->logger();
        $logger->setContext(['partnerId' => 'root']);
        $thrown = new \DomainException('handler failed');

        try {
            $logger->runWithContext(['partnerId' => 'request'], function () use ($thrown): void {
                throw $thrown;
            });
            $this->fail('the exception must propagate');
        } catch (\DomainException $caught) {
            $this->assertSame($thrown, $caught, 'rethrown unchanged');
        }

        $logger->info(self::KEY, 'after');
        $logger->flush();
        $this->assertSame('root', $this->lines()[0]['partnerId']);
    }

    public function testNestedThrowRestoresTheMiddleScopeNotTheRoot(): void
    {
        $logger = $this->logger();

        $logger->runWithContext(['partnerId' => 'middle'], function () use ($logger): void {
            try {
                $logger->runWithContext(['partnerId' => 'inner'], function (): void {
                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
            }
            $logger->info(self::KEY, 'middle again');
        });
        $logger->flush();

        $this->assertSame('middle', $this->lines()[0]['partnerId']);
    }

    // ------------------------------------------------------------------ child

    public function testAChildSharesTheParentsBufferCountersAndOnError(): void
    {
        $logger = $this->logger(['maxBufferSize' => 2]);
        $child = $logger->child(['partnerId' => 'child']);

        $child->info(self::KEY, 'one');
        $logger->info(self::KEY, 'two');
        $this->assertSame(2, $logger->stats()['buffered'], 'the child buffered into the parent');
        $this->assertSame($logger->stats(), $child->stats());

        $child->info(self::KEY, 'three'); // overflows the SHARED buffer
        $this->assertSame(1, $logger->stats()['dropped']);
        $this->assertSame(LoggerErrorEvent::REASON_BUFFER_OVERFLOW, $this->reported[0]->reason, 'the parent\'s onError');

        $child->flush();
        $this->assertCount(2, $this->captured, 'the child flushes the shared buffer (two label shapes)');
        $this->assertSame(0, $logger->stats()['buffered']);
        $this->assertSame(2, $logger->stats()['delivered']);
    }

    public function testAChildIsALoggerWithItsOwnContext(): void
    {
        $logger = $this->logger();
        $logger->setContext(['partnerId' => 'root']);
        $child = $logger->child(['requestId' => 'req-child']);

        $this->assertInstanceOf(Logger::class, $child);
        $child->setContext(['partnerId' => 'child']);
        $grandchild = $child->child();
        $grandchild->info(self::KEY, 'grandchild');
        $logger->info(self::KEY, 'root');
        $logger->flush();

        $lines = $this->lines();
        $grand = array_values(array_filter($lines, fn ($l) => $l['message'] === 'grandchild'))[0];
        $root = array_values(array_filter($lines, fn ($l) => $l['message'] === 'root'))[0];
        $this->assertSame('child', $grand['partnerId'], 'a child of a child inherits the child');
        $this->assertSame('req-child', $grand['request_id']);
        $this->assertSame('root', $root['partnerId']);
        $this->assertArrayNotHasKey('request_id', $root);
    }

    public function testRunWithContextHandsFnALoggerBoundToTheScope(): void
    {
        $logger = $this->logger();
        $kept = null;

        $logger->runWithContext(['partnerId' => 'scoped'], function (Logger $scoped) use (&$kept): void {
            $kept = $scoped;
        });
        // Used after fn returned: still that scope's context.
        $kept->info(self::KEY, 'later');
        $logger->info(self::KEY, 'root');
        $logger->flush();

        $this->assertSame('scoped', $this->lines()[0]['partnerId']);
        $this->assertArrayNotHasKey('partnerId', $this->lines()[1]);
    }

    public function testEveryPublicLoggerMethodIsDelegated(): void
    {
        // ScopedLogger never runs Logger's constructor, so an inherited
        // method would run against uninitialised state. Every public method
        // must be overridden there.
        $missing = [];
        foreach ((new \ReflectionClass(Logger::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getName() === '__construct') {
                continue;
            }
            if ($method->getDeclaringClass()->getName() === Logger::class
                && (new \ReflectionMethod(ScopedLogger::class, $method->getName()))->getDeclaringClass()->getName() !== ScopedLogger::class
            ) {
                $missing[] = $method->getName();
            }
        }

        $this->assertSame([], $missing, 'ScopedLogger must override: ' . implode(', ', $missing));
    }

    // -------------------------------------------------- snapshot & pairing

    public function testALaterSetContextNeverRelabelsAQueuedEntry(): void
    {
        $logger = $this->logger();
        $logger->runWithContext(['partnerId' => 'before'], function () use ($logger): void {
            $logger->info(self::KEY, 'queued');
            $logger->setContext(['partnerId' => 'after']);
        });
        $logger->flush();

        $this->assertSame('before', $this->lines()[0]['partnerId']);
        $this->assertSame('before', $this->captured[0]['options']['json']['labels']['partnerId']);
    }

    public function testRequestResponsePairingIsTheSameInsideAndOutsideAScope(): void
    {
        $unscoped = $this->logger();
        $this->exchange($unscoped, 'corr-same');
        $unscoped->flush();
        $outside = $this->rawLines();

        $this->captured = [];
        $scoped = $this->logger();
        $scoped->runWithContext([], fn () => $this->exchange($scoped, 'corr-same'));
        $scoped->flush();

        $this->assertSame($outside, $this->rawLines(), 'byte-identical lines either way');
    }

    // --------------------------------------------- logger-internal events

    public function testOnErrorRunsOutsideEveryScope(): void
    {
        $logger = null;
        $hooked = 0;
        $logger = $this->logger([
            'maxBufferSize' => 2,
            'onError' => function (LoggerErrorEvent $event) use (&$logger, &$hooked): void {
                // A hook that writes context on the logger writes the
                // logger-wide context — never the request that triggered it.
                if ($hooked++ === 0) {
                    $logger->setContext(['requestId' => 'from-hook']);
                }
            },
        ]);
        $logger->setContext(['partnerId' => 'root']);

        $logger->runWithContext(['partnerId' => 'request'], function () use ($logger): void {
            $logger->info(self::KEY, 'one');
            $logger->info(self::KEY, 'two');
            $logger->info(self::KEY, 'three'); // overflow → onError, from inside the scope
            $logger->info(self::KEY, 'four');
        });
        $logger->info(self::KEY, 'root line');
        $logger->flush();

        $this->assertGreaterThan(0, $hooked);
        $byMessage = [];
        foreach ($this->lines() as $line) {
            $byMessage[$line['message']] = $line;
        }
        $this->assertSame(['four', 'root line'], array_keys($byMessage));
        $this->assertArrayNotHasKey('request_id', $byMessage['four'], 'the request scope never saw the hook');
        $this->assertSame('request', $byMessage['four']['partnerId']);
        $this->assertSame('from-hook', $byMessage['root line']['request_id'], 'the hook wrote the root');
        $this->assertSame('root', $byMessage['root line']['partnerId']);
    }

    public function testTheShutdownDrainRunsOutsideEveryScope(): void
    {
        // The real process-wide hook, captured instead of registered.
        $hooks = [];
        Logger::overrideShutdownHook(static function (callable $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        try {
            $logger = $this->logger(['flushOnShutdown' => true]);
            $scopeProperty = new \ReflectionProperty(Logger::class, 'currentScope');
            $seenDuringDrain = [];
            $this->onPost = function () use ($logger, $scopeProperty, &$seenDuringDrain): void {
                $seenDuringDrain[] = $scopeProperty->getValue($logger);
            };

            // As after an `exit` inside runWithContext(): the finally that would
            // have restored the scope has not run when the shutdown hook fires.
            $logger->runWithContext(['partnerId' => 'request'], function () use ($logger, $scopeProperty, &$hooks): void {
                $logger->info(self::KEY, 'queued');
                $stuck = $scopeProperty->getValue($logger);
                $this->assertNotNull($stuck);
                $this->assertCount(1, $hooks);
                ($hooks[0])();
                $this->assertSame($stuck, $scopeProperty->getValue($logger), 'restored after the drain');
            });

            $this->assertCount(1, $this->captured);
            $this->assertSame([null], $seenDuringDrain, 'the drain ran at the root scope');
        } finally {
            Logger::overrideShutdownHook();
        }
    }
}

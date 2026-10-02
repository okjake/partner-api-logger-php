<?php

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

/**
 * A stream wrapper that records each write PHP hands it, so a test can count
 * write calls and make the sink take fewer bytes than offered.
 *
 * PHP hands a userspace stream at most its chunk size (8 KiB) per call, so
 * "one call per fwrite()" holds here only for shorter lines. Plain files and
 * pipes are not chunked (PHP 7.4+): one fwrite() is one write(2).
 *
 * @internal
 */
final class RecordingStreamWrapper
{
    public const SCHEME = 'papi5498rec';

    /** @var resource|null Set by PHP. */
    public $context;

    /** @var list<string> */
    public static array $writes = [];

    /** @var list<int> Bytes to accept on the next calls; after it, everything. */
    public static array $plan = [];

    public static int $flushes = 0;

    public static function reset(): void
    {
        self::$writes = [];
        self::$plan = [];
        self::$flushes = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $accept = self::$plan === [] ? strlen($data) : min(array_shift(self::$plan), strlen($data));
        if ($accept > 0) {
            self::$writes[] = substr($data, 0, $accept);
        }

        return $accept;
    }

    public function stream_flush(): bool
    {
        self::$flushes++;

        return true;
    }
}

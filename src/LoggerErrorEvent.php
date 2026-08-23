<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * A delivery problem, handed to the `onError` callable instead of thrown at
 * the caller.
 *
 * In the **buffered** delivery profile (see `packages/logger-spec/spec.md`) no
 * log method ever throws, so this event is the ONLY way a delivery failure
 * surfaces. `message` is deliberately the exact string the **direct** profile
 * would have raised — `"Failed to send log: …"`,
 * `"API key is required for logging"` — so anything that matched on that text
 * still matches.
 */
final class LoggerErrorEvent
{
    /** A batch could not be delivered and was discarded. */
    public const REASON_FLUSH_FAILED = 'flush-failed';

    /** The buffer was full and the oldest entries were dropped to make room. */
    public const REASON_BUFFER_OVERFLOW = 'buffer-overflow';

    /** The call itself was unusable (no API key, unserialisable data). */
    public const REASON_INVALID_ENTRY = 'invalid-entry';

    /**
     * A drain ran out of its `drainDeadlineMs` budget with entries still
     * undelivered, and discarded them.
     *
     * PHP-only. The TypeScript SDK drains on an event loop and needs no
     * wall-clock budget; here the drain is synchronous and runs inside the FPM
     * request, so it must be bounded or the worker is killed mid-drain. A
     * handler that does not know this reason still gets the `Failed to send
     * log: …` prefix on `message`.
     */
    public const REASON_DRAIN_TIMEOUT = 'drain-timeout';

    /**
     * @param string          $reason       One of the REASON_* constants.
     * @param string          $message      Human-readable description; safe to log verbatim.
     * @param int             $entryCount   How many log entries this event cost you.
     * @param int             $droppedTotal Entries this logger has dropped in total, across all causes.
     * @param int|null        $status       HTTP status, when the failure was a response status.
     * @param int|null        $attempts     Delivery attempts made before giving up (flush-failed only).
     * @param bool|null       $retryable    Whether the SDK considered the failure worth retrying.
     * @param \Throwable|null $cause        The underlying error, where there was one.
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly int $entryCount,
        public readonly int $droppedTotal = 0,
        public readonly ?int $status = null,
        public readonly ?int $attempts = null,
        public readonly ?bool $retryable = null,
        public readonly ?\Throwable $cause = null,
    ) {
    }
}

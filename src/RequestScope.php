<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * Everything that belongs to ONE request rather than to the logger
 * (PAPI-5336 / PAPI-5337; PHP parity FLT-1301).
 *
 * The logger has a root scope — what `setContext()` writes to when no request
 * scope is open, i.e. the pre-scope behaviour — and every
 * {@see Logger::runWithContext()} / {@see Logger::child()} opens a fresh one.
 * Per-request mutable state lives here, beside `context`, so it is isolated by
 * the same mechanism. A new scope never shares mutable state with the scope it
 * was opened from: it copies the context (PHP arrays are values) and starts
 * everything else empty.
 *
 * @internal Not part of the public API; the shape may change in any release.
 */
final class RequestScope
{
    /**
     * Whether this scope is a REQUEST for the upstream trail (ruling
     * 2026-09-26): it has called `logRequest` or `logResponse`. Only a request
     * scope's response line can carry calls handed up from nested scopes.
     */
    public bool $isRequest = false;

    /**
     * Mid-exchange: this scope has called `logRequest` and not yet the
     * `logResponse` that answers it.
     */
    public bool $midExchange = false;

    /**
     * The exchange this scope belongs to, decided ONCE, when it opens: the
     * nearest enclosing request if that request was mid-exchange then, else
     * none. A scope with a host is pulled onto the host's line (unless it
     * becomes a request itself) and hands off to it when it ends; a scope
     * without one is top-level and keeps its calls.
     */
    public ?RequestScope $host = null;

    /**
     * A `runWithContext()` scope ends when its callable returns or throws.
     *
     * The TypeScript SDK has a third state, "returned", because there a
     * synchronous return is not the end of a request — Express's
     * `() => next()` returns at once and the request carries on through
     * `AsyncLocalStorage`. PHP has no continuation that outlives the call
     * stack, so here a return IS the end, and the state is two-valued. A
     * `child()` and the root never end.
     */
    public bool $ended = false;

    /** Upstream calls recorded in this scope. */
    public readonly UpstreamTrail $trail;

    /**
     * For a request scope: nested scopes (not themselves requests) holding
     * calls recorded while this was their nearest request, keyed by
     * `spl_object_id` in the order they started waiting. They are pulled onto
     * this scope's trail when it responds or ends.
     *
     * @var array<int, RequestScope>
     */
    public array $helpers = [];

    /**
     * @param array<string, mixed> $context
     * @param RequestScope|null    $parent The scope active where this one was opened; null for the root.
     */
    public function __construct(
        public array $context,
        public readonly ?RequestScope $parent,
    ) {
        $this->trail = new UpstreamTrail();
    }
}

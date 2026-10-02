<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * The partner reference a pipeline log line names its partner by
 * (`packages/logger-spec/spec.md` § Partner reference, PAPI-5491).
 *
 * Stdout mode computes it for every line, so the raw app key never reaches
 * the customer's pipeline. It is public so an application that writes the
 * spec's line from its own logger (Monolog, say) computes exactly the same
 * value; the spec's reference vectors grade this function.
 *
 * The reference is an identifier, not a credential: it is derived from the
 * tenant token and the app key, so rolling the tenant token changes every
 * reference (ingest keeps resolving the previous token's references for 7
 * days after a roll).
 */
final class PartnerReference
{
    /** The scheme prefix every v1 reference starts with. */
    public const V1_PREFIX = 'v1:';

    /**
     * `v1:` + lowercase hex HMAC-SHA256, keyed with the tenant token's UTF-8
     * bytes, over the lowercase hex SHA-256 of the app key's UTF-8 bytes (the
     * 64 ASCII characters, not the 32-byte digest).
     *
     * Inputs are used exactly as given: no trimming, case folding or Unicode
     * normalisation. PHP strings are byte strings, so a UTF-8 key is hashed
     * as its UTF-8 bytes. `hash` and `hash_hmac` are core PHP (ext-hash
     * cannot be disabled since 7.4); no other extension is needed.
     */
    public static function v1(string $tenantToken, string $appKey): string
    {
        return self::V1_PREFIX . hash_hmac('sha256', hash('sha256', $appKey), $tenantToken);
    }
}

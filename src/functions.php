<?php

declare(strict_types=1);

namespace PartnerApi\Logger;

/**
 * Procedural alias matching the TypeScript public surface:
 *
 * ```php
 * use function PartnerApi\Logger\redactPII;
 * $clean = redactPII('contact alice@example.com');
 * ```
 *
 * Lives in its own file (registered via `composer.json` → `autoload.files`)
 * because PHP does not autoload functions — relying on PSR-4 alone meant the
 * alias only existed once `RedactPii` had already been loaded for some
 * unrelated reason, which broke consumers that followed the README and only
 * imported the procedural form. See PAPI-1102.
 *
 * @param string|array<mixed>|int|float|bool|null $input
 * @param array<string, bool> $options
 * @return string|array<mixed>|int|float|bool|null
 */
function redactPII(mixed $input, array $options = []): mixed
{
    return RedactPii::redact($input, $options);
}

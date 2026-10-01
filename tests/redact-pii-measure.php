<?php

// FLT-1191 — one measured `RedactPii::redact()` run for RedactPiiLinearTimeTest,
// in a process of its own so its peak memory, its memory_limit and its
// pcre.jit setting are its own. Not a test: PHPUnit collects *Test.php only.
//
//   php -d pcre.jit=0 -d memory_limit=128M redact-pii-measure.php <base64 unit> <shape> <length>
//
// Prints one JSON object: the time, the peak memory, the PCRE error, whether
// the failure marker came back, and the output's SHA-1 and first characters.

declare(strict_types=1);

use PartnerApi\Logger\RedactPii;
use PartnerApi\Logger\Tests\RedactPiiLinearTimeTest;

require __DIR__ . '/../vendor/autoload.php';

[, $unit, $shape, $length] = $argv;
$input = RedactPiiLinearTimeTest::build($shape, (string) base64_decode($unit, true), (int) $length);
unset($unit);

$started = hrtime(true);
$output = RedactPii::redact($input);
$ms = (hrtime(true) - $started) / 1e6;
$pregError = preg_last_error();

echo json_encode([
    'ms' => $ms,
    'peak' => memory_get_peak_usage(),
    'pregError' => $pregError,
    'failed' => $output === RedactPii::REDACTION_FAILED,
    'sha1' => sha1((string) $output),
    'head' => substr((string) $output, 0, 120),
], JSON_INVALID_UTF8_SUBSTITUTE), "\n";

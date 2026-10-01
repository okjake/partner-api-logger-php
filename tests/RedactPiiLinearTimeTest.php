<?php

// FLT-1191 — every `RedactPii` rule runs in time and memory linear in its
// input, with PCRE's JIT on and off, and no rule fails open. Mirrors
// packages/database/src/pii-sanitizer.linear-time.spec.ts (PAPI-4859): the same
// adversarial shapes, at the same sizes, plus the ones review found.

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\RedactPii;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class RedactPiiLinearTimeTest extends TestCase
{
    private const LENGTH = 1_000_000;
    private const SMALL = self::LENGTH / 8;

    /**
     * Each run happens in a PHP process of its own (`redact-pii-measure.php`)
     * started with this `memory_limit`, the PHP default and a common worker
     * size: a rule that runs out of memory is a fatal error no caller can
     * catch, so it must not happen on the shapes below at a megabyte.
     */
    private const MEMORY_LIMIT = '128M';

    /** What a megabyte (or three of UTF-8) may peak at, input and output included. */
    private const PEAK_BOUND_BYTES = 48 * 1024 * 1024;

    /**
     * 1 MB costing at most this many times what 125 KB costs separates linear
     * (8x) from quadratic (64x) without depending on the machine.
     */
    private const MAX_GROWTH = 24;

    /** Below this, timer noise dominates a ratio. */
    private const NOISE_FLOOR_MS = 8;

    /**
     * Timing is retried this many times before it fails; a PCRE error, the
     * failure marker, a wrong answer or a memory fatal fail at once.
     */
    private const TIMING_ATTEMPTS = 3;

    /**
     * Each shape, and the SHA-1 of what `@partner-api/logger`'s `redactPII`
     * makes of it at 1 000 000 characters — so the PHP port is held to the
     * JavaScript one at scale, not only on the corpus. Regenerate with
     * `redactPII(unit.repeat(…).slice(0, 1_000_000))` and
     * `createHash('sha1').update(out, 'utf8')` if a rule changes on purpose.
     * The two non-ASCII shapes are a million characters, so two and three
     * megabytes of UTF-8.
     *
     * Every shape is one a backtracking rule can start a match on at every
     * position, or one that once made this port allocate per character.
     *
     * The SHA-1 comes FIRST in each row: a row like `["key_", '<40 hex>']`
     * reads to gitleaks' generic-api-key rule as a credential assignment
     * (.gitleaks.toml), so no secret-shaped word may sit just before a pin.
     *
     * @return array<string, array{0: string, 1: string, 2?: string}> [sha1, unit, shape]
     */
    public static function shapes(): array
    {
        return [
            'a. repeated' => ['879acf094e7bf59eae151e719c610a6ebad69c8b', "a."],
            '1.2. repeated' => ['5e94c3dcb8b28b4f9243e617fccc5e8fd59ba129', "1.2."],
            'password= repeated' => ['a0fdd02615a279d6bbd0debb45e8232c709effd9', "password="],
            '0000- repeated' => ['05cb2568ad34ac11d74ef81297b7792e2f0bd209', "0000-"],
            'eyJ- repeated' => ['40def25543002d61fa7c0c1ff39efb0bb4ed6dee', "eyJ-"],
            'eyJa. repeated' => ['c8d288e62421a0f163943768146faf1ba15d4882', "eyJa."],
            'eyJa.b- repeated' => ['685ecaf6231a321148c395c7506e20e18a7bf1b7', "eyJa.b-"],
            'underscores' => ['8fc7234c5f1bcc26f4130bdb213a053e216dcee9', "_"],
            'dashes' => ['5fb27a83ed2cbdca85a012f2e451c76de8f69fa6', "-"],
            'underscores and dashes' => ['0a81c752c78abd248f0ad32d8a288beff9e243dc', "_-"],
            'letters' => ['de0ef2daf67e2515087cd67259b79c1a60cdc255', "a"],
            'letters and slashes' => ['ae5173afdb84c6efe2e1b7abb20146fbba4b06c6', "a/"],
            'digits and spaces' => ['1fe890a00c1e700c464b3fc4d03b59189751a0d2', "1 "],
            'plus and digits' => ['09f0e6e9281256b1494cc4da3219766464ff9916', "+1 "],
            'long digit run' => ['de0ef2daf67e2515087cd67259b79c1a60cdc255', "7"],
            'phone markers and digits' => ['b2506295dc928efe07b21cd83b53846823ab8066', "[PHONE_REDACTED] 1 "],
            'fullwidth digits' => ['2daa2544af17e0002d2f32599108472991b61cbd', "\u{FF10} "],
            'NBSP and digits' => ['deec9eaf26e218cfd5904e3f04e88543c424cd69', "0\u{A0}"],
            'nbsp entities and digits' => ['a79800845c53a0cc4939caca90c279f0e9d5279c', "0&nbsp;"],
            'at signs' => ['4dcc6310ddef571003717c5281d21b62e0a6918a', "@ "],
            'Bearer repeated' => ['3cbc9f848cfbb1c191467639022e5631dd1cc7e6', "Bearer "],
            'access_token= repeated' => ['8c778a7c61496ef7b0e935c4cda83a2fbc2a91da', "access_token="],
            'key_ repeated' => ['786322ecc296707f12b7b3c72538a2d4e9543488', "key_"],
            'GitHub prefixes after underscores' => ['d7ed4d265810d1264e423e813e5c26aa729d0038', "x_ghp_"],
            'AWS prefixes' => ['de0ef2daf67e2515087cd67259b79c1a60cdc255', "AKIA"],
            'card digits' => ['c7991a1ae2c1c1c1f71c0a51dbd8a83ae61b8684', "4111 "],
            'IP octets' => ['5e94c3dcb8b28b4f9243e617fccc5e8fd59ba129', "1.1.1."],
            'UUID heads' => ['71c130a76823afe335c31e7ba0d133f0a2570731', "3f2b8c1e-"],
            'URL schemes' => ['4d7ef5ec3bdfe9988fabdf88f8f5b5f02ebd9d39', "https://a"],
            'password JSON' => ['c54b95b1ce8bdeeec99c258031aeab508e465d8b', "\"password\":\""],
            'call context' => ['8d13abd5f5aa8b70df430c9f68101d38e25acc23', "call "],
            'escaped newlines before cards' => ['19c15bc7773c7b516ceb4afe315bf5324fc8a630', "\\n4111 "],
            'escaped newlines before eyJ' => ['01db79da9974d44a9fcf37fdcb6d1c505aa5c156', "\\neyJa."],
            'complete numbers and statuses' => ['b10d00dc0588b1889d52611cdf863f264140709a', "+44 20 7946 0000 200 "],
            'unicode escapes' => ['3fbc5025c19221dada90e203dd93ceb51cf60583', "\\u00e9"],
            'mixed' => ['606baea691de52261d4edf65415ea15a853aae64', " 0a@.-+%\\\" _eyJ"],
            // The email scan's own shapes (pii-sanitizer.linear-time.spec.ts,
            // PAPI-4885): one `@` and no way to end a host early. The first
            // exhausts PCRE's JIT stack if the host is matched by regex.
            'one @ in a long a. run' => ['0a0c3e1016bf043bca07b004c582868c5775fdac', 'a.', 'oneAtInTheMiddle'],
            'a. run ending in @' => ['38d3aef92919dd51af299b9cdafc48ea3853766f', 'a.', 'endingInAt'],
            'a. runs split by @ every 200 characters' => ['5eae449a63788fe74f88133450a7364ce06141b0', str_repeat('a.', 100) . '@'],
            // FLT-1191 review: shapes that were super-linear in time or memory
            // in an earlier cut of this port, with the JIT, without it, or at
            // a 128 MB memory_limit.
            'ampersands' => ['9d2af8728bbd0ddf5b296745db471793bde9097d', '&'],
            'query parameters' => ['8ebdf4f0a4d08f4502fe19550382630b5b3402d6', 'a=1&'],
            'URL separators' => ['31945b3120ec073a0d45497e6b4a5275bd9d193c', '://'],
            'URL separators with userinfo' => ['b5b72649dba40b151e18ce355974c9caa50f6ce6', 'x://a@'],
            'NBSP (2 MB of UTF-8)' => ['b66b883a3a97b54cdda9ed8958c48cfce66ad83d', "\u{A0}"],
            'zero-width spaces (3 MB of UTF-8)' => ['300ccaa9be1844832fbece679be394eab15311fe', "\u{200B}"],
            'path pieces with exponential humps' => ['ae5173afdb84c6efe2e1b7abb20146fbba4b06c6', 'AaAaAaAaAaAaAaAaAaAaAaAaAaAaXbc/'],
            'REST path words' => ['ba9a174474b3f88576fd82ae3f7bf14d1f136f41', 'api/v1/customers/orders/'],
            'pwd: repeated' => ['a0fdd02615a279d6bbd0debb45e8232c709effd9', 'pwd:'],
            'compact JSON behind a URL (1.25 MB)' => ['c5e8e8dcfbcb00219f11b39d74197f4c27435d4f', '{"id":1,"name":"item1"},', 'jsonWithUrl'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}> [sha1, unit, shape, jit]
     */
    public static function shapesWithAndWithoutTheJit(): array
    {
        $cases = [];
        foreach (self::shapes() as $name => $shape) {
            foreach (['JIT on' => true, 'JIT off' => false] as $jitName => $jit) {
                $cases["{$name}, {$jitName}"] = [$shape[0], $shape[1], $shape[2] ?? 'repeated', $jit];
            }
        }
        return $cases;
    }

    /**
     * The JIT off is `pcre.jit=0`, or a host that cannot allocate executable
     * memory: PCRE's interpreter then runs every pattern and has super-linear
     * paths of its own (see `RedactPii::CASELESS_START` and `NOT_SPACE_RUN`).
     */
    #[DataProvider('shapesWithAndWithoutTheJit')]
    public function testRedactsOneMegabyteInLinearTimeAndBoundedMemory(string $sha1, string $unit, string $shape, bool $jit): void
    {
        for ($attempt = 1; ; $attempt++) {
            [$problems, $timing] = self::problems($unit, $sha1, $shape, $jit);
            if ($problems !== [] || $timing === null || $attempt === self::TIMING_ATTEMPTS) {
                break;
            }
        }
        $this->assertSame([], array_merge($problems, $timing === null ? [] : [$timing]));
    }

    /**
     * What is wrong with redacting one shape at 125 KB and 1 MB, as
     * [problems that fail at once, a timing problem or null].
     *
     * @return array{0: list<string>, 1: string|null}
     */
    private static function problems(string $unit, string $sha1, string $shape, bool $jit): array
    {
        // The small input first: a super-linear rule is already visible there,
        // and running it at 1 MB would take minutes.
        $small = self::measure($unit, $shape, (int) self::SMALL, $jit);
        if (isset($small['error'])) {
            return [['125 KB: ' . $small['error']], null];
        }
        if ($small['ms'] >= self::boundMs() / 4) {
            return [[], sprintf('%.0f ms at 125 KB', $small['ms'])];
        }
        $big = self::measure($unit, $shape, self::LENGTH, $jit);
        if (isset($big['error'])) {
            return [['1 MB: ' . $big['error']], null];
        }
        $problems = [];
        if ($big['pregError'] !== PREG_NO_ERROR) {
            $problems[] = 'PCRE error ' . $big['pregError'];
        }
        if ($big['failed']) {
            $problems[] = 'redaction failed';
        } elseif ($big['sha1'] !== $sha1) {
            $problems[] = 'differs from redactPII: ' . $big['head'];
        }
        if ($big['peak'] >= self::PEAK_BOUND_BYTES) {
            $problems[] = sprintf('peaked at %.0f MB', $big['peak'] / 1e6);
        }
        $growth = $big['ms'] / max($small['ms'], self::NOISE_FLOOR_MS);
        $timing = $big['ms'] >= self::boundMs() || $growth > self::MAX_GROWTH
            ? sprintf('%.0f ms at 1 MB, %.1fx %.0f ms at 125 KB', $big['ms'], $growth, $small['ms'])
            : null;
        return [$problems, $timing];
    }

    /**
     * Every shape measures under 450 ms at 1 MB here, JIT on or off; the
     * bound is loose, and looser on a shared CI runner, because the growth
     * ratio is what tells linear from quadratic.
     */
    private static function boundMs(): float
    {
        return getenv('CI') ? 6000.0 : 2000.0;
    }

    /**
     * Run `redact-pii-measure.php` on one input in a fresh process.
     *
     * @return array{ms: float, peak: int, pregError: int, failed: bool, sha1: string, head: string}|array{error: string}
     */
    private static function measure(string $unit, string $shape, int $length, bool $jit): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'pcre.jit=' . ($jit ? '1' : '0'),
                '-d', 'memory_limit=' . self::MEMORY_LIMIT,
                '-d', 'max_execution_time=60',
                '-d', 'display_errors=stderr',
                __DIR__ . '/redact-pii-measure.php',
                base64_encode($unit),
                $shape,
                (string) $length,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return ['error' => 'could not start PHP'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $result = json_decode((string) $out, true);
        if ($status !== 0 || !is_array($result)) {
            return ['error' => "exit {$status}: " . trim($err . ' ' . $out)];
        }
        return $result;
    }

    /** One shape's input, `length` characters long, as the JavaScript spec builds it. */
    public static function build(string $shape, string $unit, int $length): string
    {
        $half = intdiv($length, 2);
        return match ($shape) {
            'oneAtInTheMiddle' => self::repeat($unit, $half) . '@' . self::repeat($unit, $length - $half - 1),
            'endingInAt' => self::repeat($unit, $length - 1) . '@',
            // A quarter over the length: PCRE's default match limit is a
            // million, and a rule that spends one step per byte of a single
            // match only fails past it.
            'jsonWithUrl' => self::JSON_PREFIX . self::repeat($unit, $length + intdiv($length, 4) - strlen(self::JSON_PREFIX)),
            default => self::repeat($unit, $length),
        };
    }

    /** A compact JSON body with no whitespace, so the URL rule's match runs to its end. */
    private const JSON_PREFIX = '{"url":"https://api.example.com/v1/orders","items":[';

    /** `unit` repeated to `length` characters. */
    private static function repeat(string $unit, int $length): string
    {
        // The non-ASCII shapes divide the length evenly, so whole repeats are
        // the same characters JavaScript's slice keeps.
        $characters = preg_match_all('/./su', $unit);
        if ($characters !== strlen($unit)) {
            return str_repeat($unit, intdiv($length, $characters));
        }
        return substr(str_repeat($unit, intdiv($length, strlen($unit)) + 1), 0, $length);
    }

    /**
     * A rule that cannot run never hands back its input. A backtrack limit of
     * 1 makes every pattern fail, which is what a super-linear rule meets on a
     * large enough input. A string comes back as the failure marker, whole; an
     * object key becomes the marker too, and a key that cannot even be split
     * into words counts as sensitive, so its value is replaced unread.
     */
    #[RunInSeparateProcess]
    public function testAPcreErrorRedactsTheWholeStringInsteadOfFailingOpen(): void
    {
        // In a process of its own, so every pattern is compiled here, with the
        // JIT off: a JIT-compiled pattern left in PCRE's cache by another test
        // does not count backtracks, and would match where this one must fail.
        $previous = ini_get('pcre.backtrack_limit');
        $previousJit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $this->assertSame(
                RedactPii::REDACTION_FAILED,
                RedactPii::redact('mail jane@realco-invented.test, card 4111 1111 1111 1111'),
            );
            $this->assertSame(
                [RedactPii::REDACTION_FAILED => '[SENSITIVE_DATA_REDACTED]'],
                RedactPii::redact(['+44 20 7946 0000' => 'call +44 20 7946 0000']),
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
            ini_set('pcre.jit', (string) $previousJit);
        }
        $this->assertSame('mail [EMAIL_REDACTED]', RedactPii::redact('mail jane@realco-invented.test'));
    }
}

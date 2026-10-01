<?php

// FLT-1191 — `RedactPii` meets the same redaction contract as the platform
// sanitiser and the JavaScript SDK: packages/database/src/pii-redaction-corpus.json
// (PAPI-4859). Mirrors packages/logger/src/redact-pii.corpus.spec.ts, so a
// corpus addition fails this suite too instead of the PHP copy drifting.

declare(strict_types=1);

namespace PartnerApi\Logger\Tests;

use PartnerApi\Logger\RedactPii;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactPiiCorpusTest extends TestCase
{
    /**
     * Read by path, as the JavaScript spec reads it: the corpus lives beside
     * the platform sanitiser, and CI checks out the whole monorepo.
     */
    private const CORPUS = __DIR__ . '/../../database/src/pii-redaction-corpus.json';

    /**
     * @return array{cases: list<array{name: string, input: mixed, expected: mixed, options?: array<string, bool>}>, secretShapeSources: list<string>, objects: list<object>}
     */
    private static function corpus(): array
    {
        $json = file_get_contents(self::CORPUS);
        self::assertIsString($json, 'pii-redaction-corpus.json must be readable at ' . self::CORPUS);
        $corpus = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        // Decoded a second time as objects so `expected` keeps what PHP arrays
        // cannot: whether a value is a JSON object or a JSON array.
        $corpus['objects'] = json_decode($json, false, 512, JSON_THROW_ON_ERROR)->cases;
        return $corpus;
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: array<string, bool>}>
     */
    public static function cases(): array
    {
        $corpus = self::corpus();
        $cases = [];
        foreach ($corpus['cases'] as $i => $case) {
            $input = $case['input'];
            // An input given as a list of strings is joined at runtime, so no
            // credential-shaped literal is committed (.gitleaks.toml).
            if (is_array($input) && array_is_list($input) && $input !== []
                && count(array_filter($input, 'is_string')) === count($input)
            ) {
                $input = implode('', $input);
            }
            $cases[$case['name']] = [
                $input,
                self::json($corpus['objects'][$i]->expected),
                $case['options'] ?? [],
            ];
        }
        return $cases;
    }

    public function testReadsANonEmptyCorpusWithUniqueNames(): void
    {
        $names = array_column(self::corpus()['cases'], 'name');
        $this->assertGreaterThan(40, count($names));
        $this->assertSame(count($names), count(array_unique($names)));
    }

    /**
     * Each case runs with `redactUuids` on unless it says otherwise: the SDK
     * and platform defaults for that option differ on purpose, and the corpus
     * pins the rules, not the defaults. The comparison is on JSON text, so key
     * order, `#2` suffixes, a dropped key and the int/string split all count.
     *
     * @param array<string, bool> $options
     */
    #[DataProvider('cases')]
    public function testMeetsTheCorpus(mixed $input, string $expected, array $options): void
    {
        $this->assertSame(
            $expected,
            self::json(RedactPii::redact($input, array_merge(['redactUuids' => true], $options))),
        );
    }

    public function testCarriesExactlyTheCredentialPrefixesInSecretShapes(): void
    {
        // The corpus lists the platform's `SECRET_SHAPES`; the database spec
        // holds it to the shared module, and this holds the PHP copy to it.
        // In order, as the JavaScript spec compares it: the sources become one
        // PCRE alternation, and an alternation tries its branches in order.
        $this->assertSame(self::corpus()['secretShapeSources'], RedactPii::SECRET_SHAPE_SOURCES);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

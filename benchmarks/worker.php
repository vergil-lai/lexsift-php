<?php

declare(strict_types=1);

use VergilLai\LexSift\Dictionary\DictionaryCompiler;
use VergilLai\LexSift\Matcher;
use VergilLai\LexSift\Matcher\AhoCorasickMatcher;
use VergilLai\LexSift\Normalizer\TextNormalizer;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return positive-int
 */
function benchmarkPositiveInteger(?string $value, string $name): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (false === $parsed) {
        throw new InvalidArgumentException($name . ' must be a positive integer.');
    }

    return $parsed;
}

/**
 * @return list<string>
 */
function benchmarkTerms(int $termCount): array
{
    $samples = ['敏感词', '联系方式', '赌博'];
    $englishCount = max(0, $termCount - count($samples));
    $terms = [];
    for ($index = 1; $index <= $englishCount; ++$index) {
        $terms[] = sprintf('term%06d', $index);
    }
    foreach (array_slice($samples, 0, $termCount - $englishCount) as $sample) {
        $terms[] = $sample;
    }

    return $terms;
}

function benchmarkExactText(string $pattern, int $codepoints): string
{
    $patternLength = mb_strlen($pattern, 'UTF-8');
    $repeats = (int) ceil($codepoints / $patternLength);

    return mb_substr(str_repeat($pattern, $repeats), 0, $codepoints, 'UTF-8');
}

function benchmarkSparseText(int $codepoints, string $term): string
{
    $termLength = mb_strlen($term, 'UTF-8');
    if ($termLength > $codepoints) {
        return benchmarkExactText('界', $codepoints);
    }

    $prefixLength = intdiv($codepoints - $termLength, 2);

    return benchmarkExactText('界', $prefixLength)
        . $term
        . benchmarkExactText('界', $codepoints - $prefixLength - $termLength);
}

/** @param list<string> $terms */
function benchmarkScanner(array $terms): Matcher
{
    return new Matcher($terms);
}

/**
 * @param list<float|int> $samples
 */
function benchmarkPercentile(array $samples, float $percentile): float
{
    if ([] === $samples) {
        throw new LogicException('Percentile samples must not be empty.');
    }

    sort($samples, SORT_NUMERIC);
    $index = max(0, (int) ceil(count($samples) * $percentile) - 1);

    return (float) $samples[$index];
}

/**
 * @return array{
 *     textCodepoints: int,
 *     normalizationMs: float,
 *     warmScanP50Ms: float,
 *     warmScanP95Ms: float,
 *     matchesPerIteration: int,
 *     matchesPerSecond: float
 * }
 */
function benchmarkScenario(
    Matcher $scanner,
    TextNormalizer $normalizer,
    string $text,
    int $iterations,
): array {
    $textCodepoints = mb_strlen($text, 'UTF-8');
    $startedAt = hrtime(true);
    $normalizer->normalize($text);
    $normalizationMs = (hrtime(true) - $startedAt) / 1_000_000;

    $samples = [];
    $emitted = 0;
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $startedAt = hrtime(true);
        $result = $scanner->scan($text);
        $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
        $emitted += count($result);
    }

    $totalMs = array_sum($samples);

    return [
        'textCodepoints' => $textCodepoints,
        'normalizationMs' => $normalizationMs,
        'warmScanP50Ms' => benchmarkPercentile($samples, 0.50),
        'warmScanP95Ms' => benchmarkPercentile($samples, 0.95),
        'matchesPerIteration' => intdiv($emitted, $iterations),
        'matchesPerSecond' => $totalMs > 0.0 ? $emitted / ($totalMs / 1000) : 0.0,
    ];
}

/**
 * @return array{p50Ms: float, p95Ms: float}
 */
function benchmarkContains(Matcher $scanner, string $text, int $iterations): array
{
    $samples = [];
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $startedAt = hrtime(true);
        if (!$scanner->contains($text)) {
            throw new RuntimeException('Dense benchmark text must contain a sensitive word.');
        }
        $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    return [
        'p50Ms' => benchmarkPercentile($samples, 0.50),
        'p95Ms' => benchmarkPercentile($samples, 0.95),
    ];
}

/**
 * @param callable(): mixed $operation
 *
 * @return array{p50Ms: float, p95Ms: float}
 */
function benchmarkOperation(callable $operation, int $iterations): array
{
    $samples = [];
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $startedAt = hrtime(true);
        $operation();
        $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    return [
        'p50Ms' => benchmarkPercentile($samples, 0.50),
        'p95Ms' => benchmarkPercentile($samples, 0.95),
    ];
}

/**
 * 在同一归一化核心上对比 ASCII 快速路径与 Unicode 回退路径。
 *
 * @return array<string, int|float>
 */
function benchmarkAsciiComparison(int $codepoints, int $iterations): array
{
    $text = benchmarkExactText('a b-c!', $codepoints);
    $noMatch = benchmarkExactText('z', $codepoints);
    $dense = 'a' . benchmarkExactText('z', $codepoints - 1);
    $fast = new TextNormalizer();
    $fallback = new TextNormalizer(asciiFastPath: false);

    if ($fast->normalizeString($text) !== $fallback->normalizeString($text)) {
        throw new RuntimeException('ASCII benchmark paths produced different normalized text.');
    }

    $matcher = new AhoCorasickMatcher();
    $fastDictionary = (new DictionaryCompiler($fast))->compile(['a']);
    $fallbackDictionary = (new DictionaryCompiler($fallback))->compile(['a']);
    $measurements = [
        'fastNormalizeString' => benchmarkOperation(
            static fn(): string => $fast->normalizeString($text),
            $iterations,
        ),
        'fallbackNormalizeString' => benchmarkOperation(
            static fn(): string => $fallback->normalizeString($text),
            $iterations,
        ),
        'fastNormalizeMapped' => benchmarkOperation(
            static fn() => $fast->normalize($text),
            $iterations,
        ),
        'fallbackNormalizeMapped' => benchmarkOperation(
            static fn() => $fallback->normalize($text),
            $iterations,
        ),
        'fastContainsNoMatch' => benchmarkOperation(
            static fn(): bool => $matcher->containsCharacters($fast->characters($noMatch), $fastDictionary),
            $iterations,
        ),
        'fallbackContainsNoMatch' => benchmarkOperation(
            static fn(): bool => $matcher->containsCharacters(
                $fallback->characters($noMatch),
                $fallbackDictionary,
            ),
            $iterations,
        ),
        'fastContainsEarlyMatch' => benchmarkOperation(
            static fn(): bool => $matcher->containsCharacters($fast->characters($dense), $fastDictionary),
            $iterations,
        ),
        'fallbackContainsEarlyMatch' => benchmarkOperation(
            static fn(): bool => $matcher->containsCharacters(
                $fallback->characters($dense),
                $fallbackDictionary,
            ),
            $iterations,
        ),
    ];

    $result = [
        'textCodepoints' => $codepoints,
        'iterations' => $iterations,
    ];
    foreach ($measurements as $name => $measurement) {
        $result[$name . 'P50Ms'] = $measurement['p50Ms'];
        $result[$name . 'P95Ms'] = $measurement['p95Ms'];
    }

    return $result;
}

/**
 * @return array{
 *     combiningMarks: array{
 *         inputCodepoints: int,
 *         normalizedCodepoints: int,
 *         mappingEntries: int,
 *         mappingAllocationDeltaBytes: int,
 *         normalizationMs: float
 *     },
 *     suffixOverlap: array{
 *         terms: int,
 *         textCodepoints: int,
 *         nodes: int,
 *         normalizationMs: float,
 *         compileMs: float,
 *         scanMs: float,
 *         matches: int,
 *         resultAllocationDeltaBytes: int
 *     },
 * }
 */
function benchmarkPathologies(TextNormalizer $normalizer): array
{
    $combiningMarks = str_repeat("\u{0301}", 10_000);
    gc_collect_cycles();
    $beforeNormalization = memory_get_usage(false);
    $startedAt = hrtime(true);
    $normalizedMarks = $normalizer->normalize($combiningMarks);
    $combiningNormalizationMs = (hrtime(true) - $startedAt) / 1_000_000;
    $afterNormalization = memory_get_usage(false);

    $overlapTermCount = 64;
    $overlapTextLength = 1000;
    $overlapTerms = [];
    for ($length = 1; $length <= $overlapTermCount; ++$length) {
        $overlapTerms[] = str_repeat('a', $length);
    }
    $startedAt = hrtime(true);
    $compiled = (new DictionaryCompiler($normalizer))->compile($overlapTerms);
    $compileMs = (hrtime(true) - $startedAt) / 1_000_000;
    $overlapText = str_repeat('a', $overlapTextLength);
    $startedAt = hrtime(true);
    $normalizer->normalize($overlapText);
    $normalizationMs = (hrtime(true) - $startedAt) / 1_000_000;

    $scanner = benchmarkScanner($overlapTerms);
    $scanner->scan('界');
    gc_collect_cycles();
    $beforeScan = memory_get_usage(false);
    $startedAt = hrtime(true);
    $result = $scanner->scan($overlapText);
    $scanMs = (hrtime(true) - $startedAt) / 1_000_000;
    $afterScan = memory_get_usage(false);

    return [
        'combiningMarks' => [
            'inputCodepoints' => mb_strlen($combiningMarks, 'UTF-8'),
            'normalizedCodepoints' => mb_strlen($normalizedMarks->normalized, 'UTF-8'),
            'mappingEntries' => count($normalizedMarks->sourceStarts),
            'mappingAllocationDeltaBytes' => max(0, $afterNormalization - $beforeNormalization),
            'normalizationMs' => $combiningNormalizationMs,
        ],
        'suffixOverlap' => [
            'terms' => $overlapTermCount,
            'textCodepoints' => $overlapTextLength,
            'nodes' => count($compiled->transitions),
            'normalizationMs' => $normalizationMs,
            'compileMs' => $compileMs,
            'scanMs' => $scanMs,
            'matches' => count($result),
            'resultAllocationDeltaBytes' => max(0, $afterScan - $beforeScan),
        ],
    ];
}

/**
 * @param list<string> $terms
 *
 * @return array{coldScanMs: float, constructionMs: float}
 */
function benchmarkConstruction(Matcher $scanner, array $terms, string $text): array
{
    $startedAt = hrtime(true);
    $scanner->scan($text);
    $coldScanMs = (hrtime(true) - $startedAt) / 1_000_000;
    $startedAt = hrtime(true);
    new Matcher($terms);
    $constructionMs = (hrtime(true) - $startedAt) / 1_000_000;

    return [
        'coldScanMs' => $coldScanMs,
        'constructionMs' => $constructionMs,
    ];
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($scriptFilename) || realpath($scriptFilename) !== __FILE__) {
    return;
}

try {
    $arguments = $_SERVER['argv'] ?? [];
    if (!is_array($arguments)) {
        throw new InvalidArgumentException('CLI arguments are unavailable.');
    }
    $termCountValue = $arguments[1] ?? null;
    $textLengthValue = $arguments[2] ?? null;
    $iterationsValue = $arguments[3] ?? null;
    $termCount = benchmarkPositiveInteger(is_string($termCountValue) ? $termCountValue : null, 'termCount');
    $textLength = benchmarkPositiveInteger(is_string($textLengthValue) ? $textLengthValue : null, 'textLength');
    $iterations = benchmarkPositiveInteger(is_string($iterationsValue) ? $iterationsValue : null, 'iterations');
    $flags = array_slice($arguments, 4);
    foreach ($flags as $rawFlag) {
        $flag = is_string($rawFlag) ? $rawFlag : '';
        if (!in_array($flag, ['--pathological', '--ascii-comparison'], true)) {
            throw new InvalidArgumentException('Unsupported benchmark option: ' . $flag);
        }
    }

    $normalizer = new TextNormalizer();
    $compiler = new DictionaryCompiler($normalizer);
    gc_collect_cycles();
    $beforeTerms = memory_get_usage(false);
    $terms = benchmarkTerms($termCount);
    $afterTerms = memory_get_usage(false);

    $startedAt = hrtime(true);
    foreach ($terms as $term) {
        $normalizer->normalizeString($term);
    }
    $normalizationMs = (hrtime(true) - $startedAt) / 1_000_000;

    $startedAt = hrtime(true);
    $compiled = $compiler->compile($terms);
    $compileMs = (hrtime(true) - $startedAt) / 1_000_000;
    $afterCompile = memory_get_usage(false);

    $texts = [
        'noMatch' => benchmarkExactText('界', $textLength),
        'sparse' => benchmarkSparseText($textLength, '敏感词'),
        'dense' => benchmarkExactText('敏感词', $textLength),
    ];
    foreach ($texts as $text) {
        if (mb_strlen($text, 'UTF-8') !== $textLength) {
            throw new RuntimeException('Generated benchmark text has an unexpected codepoint length.');
        }
    }

    $scanner = benchmarkScanner($terms);
    $scanner->scan($texts['noMatch']);
    $scenarios = [];
    foreach ($texts as $name => $text) {
        $scenarios[$name] = benchmarkScenario($scanner, $normalizer, $text, $iterations);
    }
    $denseContains = benchmarkContains($scanner, $texts['dense'], $iterations);

    $construction = benchmarkConstruction(benchmarkScanner($terms), $terms, $texts['sparse']);
    $coldScanMs = $construction['coldScanMs'];
    $constructionMs = $construction['constructionMs'];

    $nodes = count($compiled->transitions);
    $rawTermsBytes = max(0, $afterTerms - $beforeTerms);
    $compiledBytes = max(0, $afterCompile - $afterTerms);
    $sparse = $scenarios['sparse'];
    $output = [
        'terms' => $termCount,
        'textCodepoints' => $textLength,
        'iterations' => $iterations,
        'normalizationMs' => $normalizationMs,
        'compileMs' => $compileMs,
        'nodes' => $nodes,
        'rawTermsBytes' => $rawTermsBytes,
        'compiledDeltaBytes' => $compiledBytes,
        'bytesPerNode' => $compiledBytes / $nodes,
        'bytesPerTerm' => $compiledBytes / $termCount,
        'warmScanP50Ms' => $sparse['warmScanP50Ms'],
        'warmScanP95Ms' => $sparse['warmScanP95Ms'],
        'coldScanMs' => $coldScanMs,
        'matchesPerSecond' => $sparse['matchesPerSecond'],
        'constructionMs' => $constructionMs,
        'denseContainsP50Ms' => $denseContains['p50Ms'],
        'denseContainsP95Ms' => $denseContains['p95Ms'],
        'processPeakBytes' => memory_get_peak_usage(true),
        'php' => PHP_VERSION,
        'icu' => INTL_ICU_VERSION,
        'scenarios' => $scenarios,
    ];
    if (in_array('--pathological', $flags, true)) {
        $output['pathologies'] = benchmarkPathologies($normalizer);
    }
    if (in_array('--ascii-comparison', $flags, true)) {
        $output['asciiComparison'] = benchmarkAsciiComparison($textLength, $iterations);
    }
    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

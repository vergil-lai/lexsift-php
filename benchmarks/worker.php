<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Contracts\DictionaryRepositoryInterface;
use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;
use VergilLai\SensitiveText\SensitiveText;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @internal Benchmark-only fixed snapshot repository. */
final readonly class BenchmarkDictionaryRepository implements DictionaryRepositoryInterface
{
    public function __construct(private SensitiveDictionary $snapshot) {}

    public function version(): string
    {
        return $this->snapshot->version;
    }

    public function load(): SensitiveDictionary
    {
        return $this->snapshot;
    }
}

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
 * @return list<SensitiveTerm>
 */
function benchmarkTerms(int $termCount): array
{
    $samples = ['敏感词', '联系方式', '赌博'];
    $englishCount = max(0, $termCount - count($samples));
    $terms = [];
    for ($index = 1; $index <= $englishCount; ++$index) {
        $terms[] = new SensitiveTerm(sprintf('term%06d', $index));
    }
    foreach (array_slice($samples, 0, $termCount - $englishCount) as $sample) {
        $terms[] = new SensitiveTerm($sample);
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

function benchmarkScanner(SensitiveDictionary $dictionary, TextNormalizer $normalizer): SensitiveText
{
    return new SensitiveText(
        $normalizer,
        new BenchmarkDictionaryRepository($dictionary),
        [new AhoCorasickMatcher()],
        versionCheckInterval: PHP_FLOAT_MAX,
    );
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
    SensitiveText $scanner,
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
        $emitted += $result->count();
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
 *     }
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
        $overlapTerms[] = new SensitiveTerm(str_repeat('a', $length));
    }
    $overlapDictionary = new SensitiveDictionary('pathological', $overlapTerms);
    $startedAt = hrtime(true);
    $compiled = (new DictionaryCompiler($normalizer))->compile($overlapDictionary);
    $compileMs = (hrtime(true) - $startedAt) / 1_000_000;
    $overlapText = str_repeat('a', $overlapTextLength);
    $startedAt = hrtime(true);
    $normalizer->normalize($overlapText);
    $normalizationMs = (hrtime(true) - $startedAt) / 1_000_000;

    $scanner = benchmarkScanner($overlapDictionary, $normalizer);
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
            'mappingEntries' => count($normalizedMarks->offsetMap),
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
            'matches' => $result->count(),
            'resultAllocationDeltaBytes' => max(0, $afterScan - $beforeScan),
        ],
    ];
}

/**
 * @return array{coldScanMs: float, reloadMs: float, dictionaryVersion: string, terms: int}
 */
function benchmarkRedisRepository(DictionaryRepositoryInterface $repository, string $text): array
{
    $scanner = new SensitiveText(
        new TextNormalizer(),
        $repository,
        [new AhoCorasickMatcher()],
        versionCheckInterval: PHP_FLOAT_MAX,
    );
    $startedAt = hrtime(true);
    $scanner->scan($text);
    $coldScanMs = (hrtime(true) - $startedAt) / 1_000_000;
    $stats = $scanner->stats();
    $dictionaryVersion = $stats->dictionaryVersion;
    if (null === $dictionaryVersion) {
        throw new LogicException('Cold Redis scan did not load a dictionary snapshot.');
    }
    $startedAt = hrtime(true);
    $scanner->reload();
    $reloadMs = (hrtime(true) - $startedAt) / 1_000_000;

    return [
        'coldScanMs' => $coldScanMs,
        'reloadMs' => $reloadMs,
        'dictionaryVersion' => $dictionaryVersion,
        'terms' => $stats->termCount,
    ];
}

/**
 * @return array{coldScanMs: float, reloadMs: float, dictionaryVersion: string, terms: int}
 */
function benchmarkRedis(string $text): array
{
    $redisUrl = getenv('SENSITIVE_TEXT_BENCHMARK_REDIS_URL');
    if (false === $redisUrl || '' === $redisUrl) {
        throw new InvalidArgumentException(
            'SENSITIVE_TEXT_BENCHMARK_REDIS_URL is required with --redis.',
        );
    }
    $prefix = getenv('SENSITIVE_TEXT_BENCHMARK_REDIS_PREFIX');
    if (false === $prefix || '' === $prefix) {
        $prefix = 'sensitive_text:';
    }

    $repository = new RedisDictionaryRepository(
        PhpRedisClientAdapter::fromUrl($redisUrl, 1.0),
        $prefix,
    );

    return benchmarkRedisRepository($repository, $text);
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
        if (!in_array($flag, ['--pathological', '--redis'], true)) {
            throw new InvalidArgumentException('Unsupported benchmark option: ' . $flag);
        }
    }

    $normalizer = new TextNormalizer();
    $compiler = new DictionaryCompiler($normalizer);
    gc_collect_cycles();
    $beforeTerms = memory_get_usage(false);
    $terms = benchmarkTerms($termCount);
    $dictionary = new SensitiveDictionary('1', $terms);
    $afterTerms = memory_get_usage(false);

    $startedAt = hrtime(true);
    $compiled = $compiler->compile($dictionary);
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

    $scanner = benchmarkScanner($dictionary, $normalizer);
    $scanner->scan($texts['noMatch']);
    $scenarios = [];
    foreach ($texts as $name => $text) {
        $scenarios[$name] = benchmarkScenario($scanner, $normalizer, $text, $iterations);
    }

    $coldScanner = benchmarkScanner($dictionary, $normalizer);
    $startedAt = hrtime(true);
    $coldScanner->scan($texts['sparse']);
    $coldScanMs = (hrtime(true) - $startedAt) / 1_000_000;

    $startedAt = hrtime(true);
    $scanner->reload();
    $reloadMs = (hrtime(true) - $startedAt) / 1_000_000;

    $nodes = count($compiled->transitions);
    $rawTermsBytes = max(0, $afterTerms - $beforeTerms);
    $compiledBytes = max(0, $afterCompile - $afterTerms);
    $sparse = $scenarios['sparse'];
    $output = [
        'terms' => $termCount,
        'textCodepoints' => $textLength,
        'iterations' => $iterations,
        'normalizationMs' => $compiled->normalizationSeconds * 1000,
        'compileMs' => $compileMs,
        'nodes' => $nodes,
        'rawTermsBytes' => $rawTermsBytes,
        'compiledBytes' => $compiledBytes,
        'estimatedMemoryBytes' => $compiled->estimatedMemoryBytes,
        'bytesPerNode' => $compiledBytes / $nodes,
        'bytesPerTerm' => $compiledBytes / $termCount,
        'warmScanP50Ms' => $sparse['warmScanP50Ms'],
        'warmScanP95Ms' => $sparse['warmScanP95Ms'],
        'coldScanMs' => $coldScanMs,
        'matchesPerSecond' => $sparse['matchesPerSecond'],
        'reloadMs' => $reloadMs,
        'peakMemoryBytes' => memory_get_peak_usage(true),
        'php' => PHP_VERSION,
        'icu' => INTL_ICU_VERSION,
        'scenarios' => $scenarios,
    ];
    if (in_array('--pathological', $flags, true)) {
        $output['pathologies'] = benchmarkPathologies($normalizer);
    }
    if (in_array('--redis', $flags, true)) {
        $output['redis'] = benchmarkRedis($texts['sparse']);
    }

    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

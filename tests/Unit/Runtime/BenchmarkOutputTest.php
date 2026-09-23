<?php

declare(strict_types=1);

use VergilLai\LexSift\Matcher;

require_once dirname(__DIR__, 3) . '/benchmarks/worker.php';

it('emits a finite smoke benchmark result for every scan shape', function () {
    $worker = dirname(__DIR__, 3) . '/benchmarks/worker.php';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $worker, '1000', '100', '3'],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    expect($process)->toBeResource();
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start benchmark worker.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, $stderr)
        ->and(substr_count(trim($stdout), "\n"))->toBe(0);

    $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();
    if (!is_array($decoded)) {
        throw new RuntimeException('Benchmark worker did not return a JSON object.');
    }
    $result = $decoded;
    $numericFields = [
        'terms',
        'textCodepoints',
        'normalizationMs',
        'compileMs',
        'nodes',
        'rawTermsBytes',
        'compiledDeltaBytes',
        'bytesPerNode',
        'bytesPerTerm',
        'warmScanP50Ms',
        'warmScanP95Ms',
        'coldScanMs',
        'matchesPerSecond',
        'constructionMs',
        'denseContainsP50Ms',
        'denseContainsP95Ms',
        'processPeakBytes',
    ];

    $php = $result['php'] ?? null;
    $icu = $result['icu'] ?? null;
    $scenarios = $result['scenarios'] ?? null;
    expect($result['terms'] ?? null)->toBe(1000)
        ->and($result['textCodepoints'] ?? null)->toBe(100)
        ->and($php)->toBeString()
        ->and($icu)->toBeString()
        ->and($scenarios)->toBeArray();
    if (!is_string($php) || !is_string($icu) || !is_array($scenarios)) {
        throw new RuntimeException('Benchmark metadata has an unexpected type.');
    }
    expect($php)->not->toBeEmpty()
        ->and($icu)->not->toBeEmpty()
        ->and(array_keys($scenarios))->toBe(['noMatch', 'sparse', 'dense']);

    foreach ($numericFields as $field) {
        $value = $result[$field] ?? null;
        expect(is_int($value) || is_float($value))->toBeTrue();
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException('Benchmark numeric field has an unexpected type: ' . $field);
        }
        expect((float) $value)->toBeGreaterThanOrEqual(0.0)
            ->and(is_finite((float) $value))->toBeTrue();
    }
});

it('compares ascii fast and fallback paths using the same normalization core', function () {
    $result = benchmarkAsciiComparison(100, 3);

    expect($result['textCodepoints'])->toBe(100)
        ->and($result['iterations'])->toBe(3)
        ->and($result['fastNormalizeStringP50Ms'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['fallbackNormalizeStringP50Ms'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['fastContainsNoMatchP50Ms'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['fallbackContainsNoMatchP50Ms'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['fastContainsEarlyMatchP50Ms'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['fallbackContainsEarlyMatchP50Ms'])->toBeGreaterThanOrEqual(0.0);
});

it('measures cold scanning and immutable filter construction', function () {
    $scanner = new Matcher(['敏感词']);

    $result = benchmarkConstruction($scanner, ['替换词'], '这是敏感词');

    expect($result['coldScanMs'])->toBeGreaterThanOrEqual(0.0)
        ->and($result['constructionMs'])->toBeGreaterThanOrEqual(0.0);
});

<?php

declare(strict_types=1);

/**
 * @param list<string> $extraArguments
 *
 * @return array<string, mixed>
 */
function runBenchmarkWorker(int $terms, int $textCodepoints, int $iterations, array $extraArguments = []): array
{
    $command = [
        PHP_BINARY,
        __DIR__ . '/worker.php',
        (string) $terms,
        (string) $textCodepoints,
        (string) $iterations,
        ...$extraArguments,
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        return [
            'status' => 'failed',
            'terms' => $terms,
            'textCodepoints' => $textCodepoints,
            'error' => 'Unable to start benchmark worker.',
        ];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (0 !== $exitCode) {
        return [
            'status' => 'failed',
            'terms' => $terms,
            'textCodepoints' => $textCodepoints,
            'exitCode' => $exitCode,
            'error' => trim($stderr),
        ];
    }

    try {
        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [
            'status' => 'failed',
            'terms' => $terms,
            'textCodepoints' => $textCodepoints,
            'error' => 'Worker returned invalid JSON: ' . $exception->getMessage(),
        ];
    }
    if (!is_array($decoded)) {
        return [
            'status' => 'failed',
            'terms' => $terms,
            'textCodepoints' => $textCodepoints,
            'error' => 'Worker returned a non-object JSON value.',
        ];
    }

    /** @var array<string, mixed> $decoded */
    return ['status' => 'ok', ...$decoded];
}

/** @param array<string, mixed> $row */
function appendBenchmarkRow(string $path, array $row): void
{
    $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (false === file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX)) {
        throw new RuntimeException('Unable to write benchmark output: ' . $path);
    }
}

try {
    $iterationsValue = getenv('SENSITIVE_TEXT_BENCHMARK_ITERATIONS');
    $iterations = false === $iterationsValue || '' === $iterationsValue
        ? 3
        : filter_var($iterationsValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (false === $iterations) {
        throw new InvalidArgumentException('SENSITIVE_TEXT_BENCHMARK_ITERATIONS must be a positive integer.');
    }

    $resultsDirectory = __DIR__ . '/results';
    if (!is_dir($resultsDirectory) && !mkdir($resultsDirectory, 0777, true) && !is_dir($resultsDirectory)) {
        throw new RuntimeException('Unable to create benchmark results directory.');
    }
    $processId = getmypid();
    $outputPath = sprintf(
        '%s/%s-%s.jsonl',
        $resultsDirectory,
        gmdate('Ymd-His'),
        false === $processId ? 'unknown' : (string) $processId,
    );

    $termCounts = [1000, 10_000, 50_000, 100_000];
    $textLengths = [100, 1000, 10_000];
    $succeeded = 0;
    $failures = [];
    foreach ($termCounts as $termCount) {
        foreach ($textLengths as $textLength) {
            $row = runBenchmarkWorker($termCount, $textLength, $iterations);
            appendBenchmarkRow($outputPath, ['kind' => 'matrix', ...$row]);
            if ('ok' === $row['status']) {
                ++$succeeded;
            } else {
                $failures[] = [
                    'terms' => $termCount,
                    'textCodepoints' => $textLength,
                    'error' => $row['error'] ?? 'unknown failure',
                ];
            }
        }
    }

    $pathological = runBenchmarkWorker(1000, 10_000, 1, ['--pathological']);
    appendBenchmarkRow($outputPath, ['kind' => 'pathological', ...$pathological]);
    if ('ok' !== $pathological['status']) {
        $failures[] = [
            'terms' => 1000,
            'textCodepoints' => 10_000,
            'error' => $pathological['error'] ?? 'unknown pathological benchmark failure',
        ];
    }

    $host = gethostname();
    $summary = [
        'host' => false === $host ? 'unknown' : $host,
        'php' => PHP_VERSION,
        'icu' => INTL_ICU_VERSION,
        'iterations' => $iterations,
        'dataShapes' => ['noMatch', 'sparse', 'dense'],
        'matrix' => [
            'termCounts' => $termCounts,
            'textCodepoints' => $textLengths,
        ],
        'succeeded' => $succeeded,
        'failed' => count($failures),
        'failures' => $failures,
        'rawJsonPath' => $outputPath,
    ];
    echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    exit([] === $failures ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

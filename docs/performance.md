# Performance benchmark

The benchmark measures the real `TextNormalizer`, `DictionaryCompiler`, and
`SensitiveText` scan path. It is a local comparison tool, not a portable claim
about production latency.

## Reproducing the measurements

Run the smoke worker directly:

```bash
php benchmarks/worker.php 1000 100 3
```

The worker prints exactly one JSON object. The three required arguments are the
term count, input length in Unicode code points, and measured scan iterations.
It creates fixed-width terms such as `term000001` plus Chinese samples. Every
generated input is checked with `mb_strlen()` against its requested length.

Run the complete matrix with:

```bash
php benchmarks/run.php
```

The runner synchronously starts a fresh PHP process for each of
`1000/10000/50000/100000` terms and `100/1000/10000` code points. It does not
fork loaded objects or scan in parallel. The default is three iterations per
case; set `SENSITIVE_TEXT_BENCHMARK_ITERATIONS` to a positive integer to change
it. Raw JSONL is written below `benchmarks/results/`, which is intentionally
git-ignored, and the final summary prints the exact path, host, PHP/ICU versions,
iterations, data shapes, and any failed combinations.

The shapes are:

- `noMatch`: a character absent from the dictionary;
- `sparse`: one Chinese dictionary term surrounded by non-matching text;
- `dense`: a repeated Chinese term, including result-object allocation cost.

Top-level warm-scan fields describe the sparse case for a compact comparison.
The `scenarios` object contains the separate no-match, sparse, and dense values.
`normalizationMs` at the top level is dictionary-term normalization during
compilation; each scenario also reports input normalization separately.
`compileMs` covers the complete `DictionaryCompiler::compile()` call. A cold
scan always uses a new scanner, while warm scans reuse its compiled snapshot.

`rawTermsBytes` and `compiledBytes` are process memory deltas from
`memory_get_usage(false)`. They are useful for same-machine comparisons but are
allocator- and runtime-dependent. `peakMemoryBytes` is the process high-water
allocation from `memory_get_peak_usage(true)`. `estimatedMemoryBytes`, including
the value exposed by scanner stats, is a serialized-structure estimate. None of
these values is an exact or exclusive object-size measurement.

## Baseline recorded on 2026-09-22

Environment: `VergilMacBookPro`, PHP 8.5.4, ICU 78.2, three iterations. All 12
matrix workers completed. The ignored raw file for this run was
`benchmarks/results/20260922-021033-22030.jsonl`.

The following compilation rows use the 1000-code-point run for each dictionary
size. MiB values divide bytes by 1,048,576.

| Terms | Nodes | Compile ms | Raw terms MiB | Compiled delta MiB | Peak MiB |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 1,124 | 19.773 | 0.55 | 0.57 | 10.00 |
| 10,000 | 11,123 | 195.858 | 5.02 | 5.32 | 40.00 |
| 50,000 | 55,567 | 1,019.662 | 24.46 | 24.64 | 161.00 |
| 100,000 | 111,122 | 2,002.740 | 48.86 | 49.31 | 316.67 |

Warm-scan rows below use the 100,000-term dictionary. Times are p50 wall-clock
milliseconds; cold scan includes load, compile, normalization, matching, and
result allocation in a new scanner.

| Text code points | No match | Sparse | Dense | Cold scan ms |
| ---: | ---: | ---: | ---: | ---: |
| 100 | 0.187 | 0.210 | 0.295 | 1,994.803 |
| 1,000 | 1.950 | 1.940 | 7.903 | 2,042.191 |
| 10,000 | 19.102 | 19.293 | 555.840 | 2,032.185 |

The runner also records two pathological cases. Normalizing 10,000 combining
marks took 19.624 ms, produced 10,000 mapping entries, and retained a 1,939,496
byte (1.85 MiB) process-memory delta. A 64-term suffix chain scanned against
1,000 `a` code points emitted 61,984 matches in 1,243.695 ms; retaining that
result increased measured process usage by 19,703,576 bytes (18.79 MiB). This is
result-allocation pressure, not ordinary no-match scan cost.

## Regression comparisons

Compare results only on the same machine, PHP/ICU versions, iteration count,
and data shape. Run the full matrix three times, compare the medians, and inspect
compile time, warm p50/p95, cold/reload time, process deltas, and peak memory
together. A single slower run is not a regression. CI tests intentionally assert
only the output contract and finite non-negative values; they do not use absolute
millisecond thresholds.

`matchesPerSecond` counts emitted matches, so the no-match value is zero and is
not a text-throughput measure. Dense values include normalization, match-object
creation, sorting, and deduplication. Keep the JSONL failure rows: if a child is
killed by an out-of-memory condition before it returns JSON, the runner can
identify the failed matrix point but cannot recover that child's final peak.

## Redis and fork boundaries

Algorithm measurements use the fixed in-process benchmark repository. To
measure an already-published real Redis snapshot separately, use the explicit
read-only mode:

```bash
SENSITIVE_TEXT_BENCHMARK_REDIS_URL=tcp://127.0.0.1:6379 \
SENSITIVE_TEXT_BENCHMARK_REDIS_PREFIX=sensitive_text: \
php benchmarks/worker.php 1000 1000 3 --redis
```

The additional `redis` object reports network-inclusive cold and reload times;
it is not mixed into the algorithm fields. This baseline did not run Redis mode.

V1 uses the synchronous batch executor. There is no Fork executor, so this
document does not invent a Sync/Fork comparison and does not recommend
`spatie/fork` for one text. A future `ForkBatchExecutor` is worth considering
only for CLI/offline workloads after fixed 100/1000/10000-code-point datasets
show better throughput and acceptable aggregate resident memory. Every child
must create its own Redis connection. Fork execution remains disabled for HTTP
and Octane workers.

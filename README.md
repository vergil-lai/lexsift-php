# Sensitive Text

Unicode-aware sensitive-text detection for PHP 8.2+, backed by a versioned Redis dictionary. The package maps matches back to the original text, supports Aho-Corasick and regex rules, and can run as plain PHP or through Laravel package discovery.

## Installation

```bash
composer require vergil-lai/sensitive-text
```

## Requirements

- PHP 8.2 or newer;
- `ext-intl` and `ext-mbstring`;
- `ext-redis` (phpredis), required by Composer;
- Redis 7 for the tested dictionary protocol.

`ext-redis` is a hard dependency: Composer blocks installation when it is missing. Predis is not supported and there is no runtime fallback or client selection. Redis Cluster is not supported because the atomic dictionary scripts require both keys on one primary; a single primary or a Sentinel-selected primary is supported.

## Quick Start

The package does not contain a business sensitive-word list. Publish your own dictionary before the first scan:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\SensitiveText;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379, 1.0);
$repository = new RedisDictionaryRepository(new PhpRedisClientAdapter($redis));
// 仅首次创建；后续发布传入实际 expectedVersion，冲突后重新读取与合并。
$repository->publish([new SensitiveTerm('微信', 'contact', Severity::Medium, Action::Review)], null);
$result = SensitiveText::instance()->scan('请加我微❤️信联系');
echo $result->matches()[0]->matchedText;
echo $result->mask('*');
```

The default scanner connects lazily to `tcp://127.0.0.1:6379`, uses the `sensitive_text:` prefix, and checks the dictionary version at most once every five seconds.

## Architecture

The scan path is synchronous and stateless per request:

1. `RedisDictionaryRepository` atomically reads a version and JSON payload.
2. `TextNormalizer` normalizes dictionary terms and input while retaining original code-point spans.
3. `DictionaryCompiler` builds an immutable Aho-Corasick snapshot.
4. Aho-Corasick and configured regex matchers emit `MatchResult` values.
5. `WhitelistMatcher` filters matching ranges and `ScanResult` exposes policy and masking helpers.

The compiled snapshot is reused until polling or `invalidate()` triggers a successful replacement. An automatic reload failure keeps the last-known-good snapshot; an initial or explicit `reload()` failure throws a dictionary exception.

### Public API

The supported entry points are:

- `SensitiveText::instance()`, `fromConfig()`, `scan()`, `reload()`, `invalidate()`, and `stats()`;
- `SensitiveTextConfig` and `NormalizerConfig` for plain PHP configuration;
- `RedisDictionaryRepository::version()`, `load()`, and `publish()` plus `PhpRedisClientAdapter`;
- `SensitiveTerm`, `SensitiveDictionary`, `MatchResult`, `ScanResult`, and `ScannerStats` data objects;
- `RegexRule`, `WhitelistRule`, `Action`, `Severity`, `RegexTarget`, and `WhitelistMode` rule types;
- `SyncBatchExecutor::scan()` and `RuntimeEnvironment::detect()`;
- the contracts under `VergilLai\SensitiveText\Contracts` for repositories, matchers, clocks, Redis protocol adapters, and batch executors;
- Laravel's `SensitiveTextServiceProvider` and `Laravel\Facades\SensitiveText` facade.

`ScanResult` provides `matches()`, `matched()`, `count()`, `highestSeverity()`, `recommendedAction()`, `shouldBlock()`, `shouldReview()`, and `mask()`. `Action` precedence is `allow < flag < review < block`; `Severity` is `Low=1`, `Medium=2`, `High=3`, and `Critical=4`.

Matcher/compiler/codec classes are public for advanced composition, but the entry points above cover normal use. `Support\DefaultScanner` is internal and must not be imported.

The complete advanced surface is:

| Type | Public surface |
| --- | --- |
| `TextNormalizer` | constructor and `normalize()` |
| `NormalizedText` / `SourceSpan` | immutable mapping fields plus `span()`, `sliceOriginal()`, and `normalizedRangeForOriginal()` |
| `DictionaryCompiler` / `DictionaryJsonCodec` | `compile()`, `encode()`, and `decode()` |
| `CompiledDictionary` | immutable compiled snapshot fields |
| `AhoCorasickCompiler` / `AhoCorasickMatcher` | `compile()` and `match()` |
| `RegexMatcher` / `WhitelistMatcher` | constructors plus `match()` and `filter()` |
| `PhpRedisClientAdapter` | constructor, `fromUrl()`, `get()`, `readSnapshot()`, and `compareAndSwap()` |
| `LaravelRedisAdapter` | constructor, `lazy()`, `get()`, `readSnapshot()`, and `compareAndSwap()` for Laravel phpredis connections |
| `SystemClock` | `monotonic()` and `wallTime()` |
| `SensitiveTerm`, `SensitiveDictionary`, `MatchResult`, `ScannerStats` | immutable public constructor fields |
| contracts | `BatchExecutorInterface::scan()`, `ClockInterface::monotonic()/wallTime()`, `DictionaryRepositoryInterface::version()/load()`, `MatcherInterface::match()`, and `RedisClientInterface::get()/readSnapshot()/compareAndSwap()` |
| exceptions | `SensitiveTextException`, `DictionaryException`, `DictionaryCompileException`, `RedisUnavailableException`, `InvalidConfigurationException`, `InvalidRuleException`, and `NormalizationException` |

The default policies are:

| Setting | Default |
| --- | --- |
| Redis URL / Laravel connection | `tcp://127.0.0.1:6379` / `default` |
| Redis prefix | `sensitive_text:` |
| Dictionary / version key suffix | `dictionary` / `dictionary:version` |
| Redis timeout (plain PHP) | `1.0` second |
| Version check interval | `5.0` seconds |
| Mask | `*` |
| Reload policy | keep the last good snapshot for automatic reload; explicit reload throws |
| Batch driver | synchronous |
| Regex rules / whitelist rules | empty |

## Redis Setup

Plain PHP accepts `tcp://` and `tls://` URLs, optional URL-encoded ACL credentials, and an optional database path:

```php
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\SensitiveTextConfig;

$scanner = SensitiveText::fromConfig(new SensitiveTextConfig(
    redisUrl: 'tcp://user:password@127.0.0.1:6379/2',
    redisPrefix: 'my_app:sensitive_text:',
    redisTimeout: 1.0,
));
```

The prefix, dictionary key, and version key compose into two dedicated keys. Do not set TTLs on either key and do not write around the repository's compare-and-swap publication. The complete atomic protocol is in [docs/dictionary-protocol.md](docs/dictionary-protocol.md).

## Dictionary Format

The JSON payload is schema version 1:

```json
{"schema":1,"terms":[{"term":"赌博","category":"gambling","severity":3,"action":"block","enabled":true,"metadata":{"source":"manual"}}]}
```

`term` and `category` are non-empty strings. `severity` is `1..4`, `action` is `allow`, `flag`, `review`, or `block`, and `enabled` is boolean. Metadata accepts JSON scalars/null or one nested array/object of scalars/null. Disabled terms remain in the source dictionary but are not compiled.

Dictionary versions are non-negative decimal strings without leading zeroes, up to `9223372036854775807`. The first publication uses `expectedVersion: null` and produces version `1`; every later writer must pass the version it read. A stale expected version raises `DictionaryException` instead of overwriting another publisher.

## Normalization

Defaults are NFKC normalization, lowercase conversion, whitespace removal, emoji removal, punctuation retention, symbol retention, and no custom removed characters. This conservative default favors fewer false positives. `NormalizerConfig::aggressive()` additionally removes punctuation and symbols and should be enabled only after evaluating your corpus.

```php
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\SensitiveTextConfig;

$config = new SensitiveTextConfig(
    normalizer: new NormalizerConfig(
        removeWhitespace: true,
        removePunctuation: false,
        removeSymbols: false,
        removeEmoji: true,
        removeCharacters: ['·'],
    ),
);
```

Offsets are zero-based UTF-8 Unicode code-point ranges `[start, end)`, never byte offsets. `matchedText` is always the exact original slice, so removed spaces or emoji may appear inside a dictionary match.

## Aho-Corasick

Enabled dictionary terms are normalized once and compiled into an immutable Aho-Corasick automaton. A single scan emits overlapping dictionary matches, maps each normalized range to the original text, and deduplicates identical results. Compilation happens on first use and after a successful dictionary replacement.

## Regex Rules

Regex rules use `/`, `~`, or `#` delimiters and must include the Unicode `u` modifier. Rules can target original or normalized text:

```php
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\RegexRule;
use VergilLai\SensitiveText\Rules\RegexTarget;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\SensitiveTextConfig;

$config = new SensitiveTextConfig(regexRules: [
    new RegexRule(
        id: 'wechat-id',
        pattern: '/wx[a-z0-9_]{4,}/iu',
        category: 'contact',
        severity: Severity::High,
        action: Action::Review,
        target: RegexTarget::Original,
    ),
]);
```

PCRE's normal non-overlapping behavior applies within one rule. Matches from separate rules and the dictionary may overlap.

## Whitelist

Whitelist rules suppress detected ranges; they do not make a semantic decision about intent. `Phrase` mode suppresses matches contained by an occurrence of the whitelist text. `Exact` mode suppresses only a match with the same original range.

```php
use VergilLai\SensitiveText\Rules\WhitelistMode;
use VergilLai\SensitiveText\Rules\WhitelistRule;
use VergilLai\SensitiveText\SensitiveTextConfig;

$config = new SensitiveTextConfig(whitelistRules: [
    new WhitelistRule('微信支付', WhitelistMode::Phrase),
]);
```

Treat whitelists as precise range exceptions and test them against production-language examples; they are intentionally non-semantic.

## Mask

`$result->mask()` uses the configured default `*`; `$result->mask('#')` overrides it for one result, and an empty string removes matched ranges. The mask must be empty or one valid Unicode code point. Overlapping ranges are merged, and the mask count equals the number of original Unicode code points in the merged range. Therefore a match such as `微❤️信` can produce more mask characters than the normalized dictionary term because the original emoji/variation-selector code points remain part of the span.

## Dictionary Reload

The default `versionCheckInterval` is `5.0` seconds. A scan inside that window reuses the compiled snapshot. After the interval, the scanner reads the version and recompiles only when it changed.

- `reload()` immediately loads and compiles, and propagates failures;
- `invalidate()` clears the polling timestamp so the next scan checks immediately;
- `stats()` reports version, term/node counts, compile duration, memory estimate, check/reload times, and the last reload error.

V1 provides periodic polling and an explicit invalidation interface. It does not start a Pub/Sub listener. Call `invalidate()` from your application's existing notification path if you need faster convergence.

## Laravel Usage

Laravel 12 and 13 integration is optional and discovered from Composer metadata. The host application must use Laravel's phpredis driver:

```dotenv
REDIS_CLIENT=phpredis
```

Publish the package config when you need to customize it:

```bash
php artisan vendor:publish --tag=sensitive-text-config
```

Resolve the singleton through the container or facade:

```php
use VergilLai\SensitiveText\Laravel\Facades\SensitiveText as SensitiveTextFacade;
use VergilLai\SensitiveText\SensitiveText;

$fromContainer = app(SensitiveText::class)->scan($request->string('content')->toString());
$fromFacade = SensitiveTextFacade::scan('待检测文本');
```

`config/sensitive-text.php` contains scalar-only cached configuration for the Redis connection name/prefix, dictionary key suffixes, normalizer, polling interval, mask, regex rules, whitelists, fixed `keep_last_good` reload policy, and fixed `sync` batch driver. Configure timeouts and credentials on the named Laravel Redis connection in `config/database.php`; the package does not mutate it.

Laravel may already prepend a connection-level Redis prefix. The package's `redis.prefix` is added to the keys sent through that connection, so do not repeat the same prefix in both places.

## Octane Usage

The Laravel binding is a process singleton with lazy Redis connection resolution. It does not capture a request, user, facade result, or application snapshot. It also does not register Octane ticks or worker hooks; normal scans perform the bounded version polling.

The code is designed for long-running workers, but Swoole, OpenSwoole, RoadRunner, and FrankenPHP have not all been certified on a real host. Validate your chosen runtime and Redis connection lifecycle before production rollout.

## Batch Scanning

V1 provides only the synchronous executor and preserves iterable keys:

```php
use VergilLai\SensitiveText\Runtime\SyncBatchExecutor;

$executor = new SyncBatchExecutor($scanner);
foreach ($executor->scan(['first' => '正常文本', 'second' => '联系微信']) as $key => $result) {
    echo $key.': '.($result->matched() ? 'matched' : 'clean').PHP_EOL;
}
```

There is no built-in asynchronous or fork executor.

## Performance

Normalization, compilation, network loading, match density, and result allocation affect different parts of latency. Reuse a scanner within a worker so compilation is amortized. Dense and pathological overlapping results may allocate much more memory than no-match scans. See [docs/performance.md](docs/performance.md) for the recorded baseline and comparison method.

## Worker Safety

Compiled Automaton is reused inside long-running workers.

request-specific state is never stored on singleton services.

Fiber is not used in the core scan path.

Fork-based processing is intended for CLI/offline batch workloads only.

The current release does not implement fork processing. If a future CLI executor is added, each child must establish its own Redis connection; do not fork an already-connected client or use fork processing in HTTP/Octane workers.

## Error Handling

Operational package failures derive from `SensitiveTextException`. Configuration/rule/normalization failures use `InvalidConfigurationException`, `InvalidRuleException`, and `NormalizationException`. Dictionary protocol, compilation, and Redis failures use `DictionaryException`, `DictionaryCompileException`, and `RedisUnavailableException`. Public value objects use PHP's `InvalidArgumentException` when constructor data or match ranges violate their local invariants.

Automatic refresh keeps a previously compiled snapshot and records a sanitized error in `stats()`. Initial load and explicit `reload()` cannot serve an old snapshot and throw. Do not catch these exceptions as a signal to switch Redis clients; there is no Predis or non-atomic fallback.

## Testing

```bash
composer test
SENSITIVE_TEXT_REDIS_TESTS=1 composer test
composer test:release
```

The Redis integration suite and release smoke require a dedicated Redis instance on `127.0.0.1:6379` by default. Override `SENSITIVE_TEXT_REDIS_HOST` and `SENSITIVE_TEXT_REDIS_PORT` when necessary. Tests use random prefixes and remove only their own two dictionary keys; they never run `FLUSHDB` or `FLUSHALL`.

## PHPStan

```bash
composer stan
```

PHPStan runs at level `max` over `src`, `tests`, `config`, and `benchmarks`.

## PHP-CS-Fixer

```bash
composer cs
composer cs:fix
```

`composer cs` is read-only; `composer cs:fix` applies the repository style rules.

## Benchmark

```bash
php benchmarks/worker.php 1000 100 3
php benchmarks/run.php
```

The worker prints one JSON record. The full runner covers 1,000–100,000 terms and 100–10,000 code-point texts. Benchmark results are machine-specific evidence, not a portable latency guarantee. Optional read-only Redis timing is documented in [docs/performance.md](docs/performance.md).

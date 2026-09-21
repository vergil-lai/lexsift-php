<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryCompileException;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Exception\RedisUnavailableException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\SensitiveTextConfig;
use VergilLai\SensitiveText\Tests\Helpers\FakeClock;
use VergilLai\SensitiveText\Tests\Helpers\FakeRepository;

it('reuses and replaces snapshots while retaining last known good on failure', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('赌博')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);

    expect($scanner->scan('赌博')->matched())->toBeTrue();

    $repo->snapshot = new SensitiveDictionary('2', [new SensitiveTerm('微信')]);
    expect($scanner->scan('微信')->matched())->toBeFalse();

    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and($scanner->stats()->dictionaryVersion)->toBe('2');

    $repo->fail = true;
    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and(fn() => $scanner->reload())->toThrow(RedisUnavailableException::class)
        ->and($scanner->stats()->dictionaryVersion)->toBe('2')
        ->and($scanner->stats()->lastReloadError)
        ->toBe(RedisUnavailableException::class . ': dictionary refresh failed')
        ->and($scanner->stats()->lastReloadError)->not->toContain('Injected Redis failure');

    $repo->fail = false;
    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and($scanner->stats()->lastReloadError)
        ->toBe(RedisUnavailableException::class . ': dictionary refresh failed');

    $scanner->reload();
    expect($scanner->stats()->lastReloadError)->toBeNull();
});

it('keeps an automatic refresh error until a replacement succeeds', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);
    $scanner->scan('微信');

    $repo->fail = true;
    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and($scanner->stats()->lastReloadError)
        ->toBe(RedisUnavailableException::class . ': dictionary refresh failed');

    $repo->fail = false;
    $clock->advance(5);
    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and([$repo->versionCalls, $repo->loadCalls])->toBe([2, 1])
        ->and($scanner->stats()->lastReloadError)
        ->toBe(RedisUnavailableException::class . ': dictionary refresh failed');

    $repo->snapshot = new SensitiveDictionary('2', [new SensitiveTerm('新词')]);
    $clock->advance(5);
    expect($scanner->scan('新词')->matched())->toBeTrue()
        ->and($scanner->stats()->lastReloadError)->toBeNull();
});

it('loads lazily and exposes stats without causing repository IO', function () {
    $repo = new FakeRepository(new SensitiveDictionary('7', [new SensitiveTerm('微信')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);

    $initial = $scanner->stats();
    expect($repo->versionCalls)->toBe(0)
        ->and($repo->loadCalls)->toBe(0)
        ->and($initial->dictionaryVersion)->toBeNull()
        ->and($initial->termCount)->toBe(0)
        ->and($initial->automatonNodeCount)->toBe(0)
        ->and($initial->lastCompileDuration)->toBe(0.0)
        ->and($initial->lastReloadAt)->toBeNull()
        ->and($initial->estimatedMemoryBytes)->toBe(0)
        ->and($initial->versionLastCheckedAt)->toBeNull()
        ->and($initial->lastReloadError)->toBeNull();

    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and($repo->versionCalls)->toBe(0)
        ->and($repo->loadCalls)->toBe(1)
        ->and($scanner->stats()->dictionaryVersion)->toBe('7')
        ->and($scanner->stats()->termCount)->toBe(1)
        ->and($scanner->stats()->automatonNodeCount)->toBeGreaterThan(1)
        ->and($scanner->stats()->estimatedMemoryBytes)->toBeGreaterThan(0)
        ->and($scanner->stats()->lastReloadAt)->not->toBeNull()
        ->and($scanner->stats()->versionLastCheckedAt)->not->toBeNull();
});

it('accepts an empty dictionary as a valid replacement', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);

    expect($scanner->scan('微信')->matched())->toBeTrue();
    $repo->snapshot = new SensitiveDictionary('2', []);
    $clock->advance(5);

    expect($scanner->scan('微信')->matched())->toBeFalse()
        ->and($scanner->stats()->dictionaryVersion)->toBe('2')
        ->and($scanner->stats()->termCount)->toBe(0);
});

it('keeps the previous snapshot when a candidate cannot compile', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);

    expect($scanner->scan('微信')->matched())->toBeTrue();
    $repo->snapshot = new SensitiveDictionary('2', [new SensitiveTerm('❤️')]);
    $clock->advance(5);

    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and($scanner->stats()->dictionaryVersion)->toBe('1')
        ->and($scanner->stats()->lastReloadError)->toContain(DictionaryCompileException::class)
        ->and($scanner->stats()->lastReloadError)->not->toContain('❤️');
});

it('throttles failed refreshes and lets invalidate force the next check', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);
    $scanner->scan('微信');

    $repo->fail = true;
    $clock->advance(5);
    $scanner->scan('微信');
    $failedCheckAt = $scanner->stats()->versionLastCheckedAt;
    expect([$repo->versionCalls, $repo->loadCalls])->toBe([1, 1]);

    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $scanner->scan('微信');
    }
    expect([$repo->versionCalls, $repo->loadCalls])->toBe([1, 1])
        ->and($scanner->stats()->versionLastCheckedAt)->toBe($failedCheckAt);

    $clock->advance(1);
    $scanner->invalidate();
    $scanner->scan('微信');
    expect([$repo->versionCalls, $repo->loadCalls])->toBe([2, 1])
        ->and($scanner->stats()->versionLastCheckedAt)->not->toBe($failedCheckAt);
});

it('throttles initial failures while no snapshot is available', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $repo->fail = true;
    $clock = new FakeClock();
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()], clock: $clock);

    expect(fn() => $scanner->scan('微信'))->toThrow(RedisUnavailableException::class);
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        expect(fn() => $scanner->scan('微信'))->toThrow(DictionaryException::class);
    }
    expect($repo->loadCalls)->toBe(1);

    $clock->advance(5);
    expect(fn() => $scanner->scan('微信'))->toThrow(RedisUnavailableException::class);
    expect($repo->loadCalls)->toBe(2);
});

it('keeps an existing result immutable across reloads', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);
    $old = $scanner->scan('微信');

    $repo->snapshot = new SensitiveDictionary('2', []);
    $scanner->reload();

    expect($old->original)->toBe('微信')
        ->and($old->matched())->toBeTrue()
        ->and($old->matches()[0]->matchedText)->toBe('微信')
        ->and($scanner->scan('微信')->matched())->toBeFalse();
});

it('validates scanner construction', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', []));

    expect(fn() => new SensitiveText(new TextNormalizer(), $repo, []))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveText(
            new TextNormalizer(),
            $repo,
            [new AhoCorasickMatcher()],
            versionCheckInterval: -0.1,
        ))->toThrow(InvalidConfigurationException::class);
});

it('defines and validates package configuration', function () {
    $normalizer = new NormalizerConfig(removeWhitespace: false);
    $config = new SensitiveTextConfig(normalizer: $normalizer);

    expect($config->normalizer)->toBe($normalizer)
        ->and($config->redisUrl)->toBe('tcp://127.0.0.1:6379')
        ->and($config->redisPrefix)->toBe('sensitive_text:')
        ->and($config->dictionaryKey)->toBe('dictionary')
        ->and($config->versionKey)->toBe('dictionary:version')
        ->and($config->versionCheckInterval)->toBe(5.0)
        ->and($config->redisTimeout)->toBe(1.0)
        ->and($config->maskCharacter)->toBe('*')
        ->and($config->regexRules)->toBe([])
        ->and($config->whitelistRules)->toBe([]);
});

it('rejects invalid package configuration', function () {
    expect(fn() => new SensitiveTextConfig(versionCheckInterval: -0.1))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(redisTimeout: 0.0))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(redisTimeout: -1.0))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(dictionaryKey: ''))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(versionKey: ''))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(dictionaryKey: 'same', versionKey: 'same'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(maskCharacter: '**'))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new SensitiveTextConfig(maskCharacter: "\xFF"))
        ->toThrow(InvalidConfigurationException::class);
});

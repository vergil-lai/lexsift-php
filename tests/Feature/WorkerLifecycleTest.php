<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Contracts\MatcherInterface;
use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Exception\InvalidRuleException;
use VergilLai\SensitiveText\Exception\NormalizationException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Normalizer\NormalizedText;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Result\ScanResult;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\Tests\Helpers\FakeRepository;

it('does not retain request-specific results', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);

    $expected = [
        ['微❤️信', true, [[0, 4, '微❤️信']]],
        ['', false, []],
        ['正常', false, []],
        ['微信', true, [[0, 2, '微信']]],
    ];
    foreach ($expected as [$text, $matched, $matches]) {
        $result = $scanner->scan($text);
        expect($result->original)->toBe($text)
            ->and($result->matched())->toBe($matched)
            ->and(array_map(
                static fn(MatchResult $match): array => [$match->start, $match->end, $match->matchedText],
                $result->matches(),
            ))->toBe($matches);
    }

    expect($repo->loadCalls)->toBe(1);
});

it('uses the previous snapshot for a scan reentered during reload', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('旧词')]));
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);
    $scanner->scan('旧词');
    $repo->snapshot = new SensitiveDictionary('2', [new SensitiveTerm('新词')]);

    $reentered = null;
    $repo->onLoad = function () use (&$reentered, $repo, $scanner): void {
        $repo->onLoad = null;
        $reentered = $scanner->scan('旧词');
    };
    $scanner->reload();

    expect($reentered)->not->toBeNull()
        ->and($reentered)->toBeInstanceOf(ScanResult::class)
        ->and($reentered?->matched())->toBeTrue()
        ->and($scanner->scan('旧词')->matched())->toBeFalse()
        ->and($scanner->scan('新词')->matched())->toBeTrue();
});

it('rejects a scan reentered during the first load and clears the guard', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('微信')]));
    $scanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);

    $reentryError = null;
    $repo->onLoad = function () use (&$reentryError, $repo, $scanner): void {
        $repo->onLoad = null;
        try {
            $scanner->scan('微信');
        } catch (DictionaryException $exception) {
            $reentryError = $exception;
        }
    };

    expect($scanner->scan('微信')->matched())->toBeTrue()
        ->and($reentryError)->toBeInstanceOf(DictionaryException::class);

    $repo->fail = true;
    expect(fn() => $scanner->reload())->toThrow(DictionaryException::class);
    $repo->fail = false;
    expect(fn() => $scanner->reload())->not->toThrow(DictionaryException::class);
});

it('keeps one local snapshot throughout a scan', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', [new SensitiveTerm('旧词')]));
    $reloadDuringMatch = new class ($repo) implements MatcherInterface {
        public ?SensitiveText $scanner = null;

        public function __construct(private readonly FakeRepository $repository) {}

        public function match(NormalizedText $text, CompiledDictionary $dictionary): array
        {
            $this->repository->snapshot = new SensitiveDictionary('2', [new SensitiveTerm('新词')]);
            $this->scanner?->reload();

            return [];
        }
    };
    $scanner = new SensitiveText(
        new TextNormalizer(),
        $repo,
        [$reloadDuringMatch, new AhoCorasickMatcher()],
    );
    $reloadDuringMatch->scanner = $scanner;

    expect($scanner->scan('旧词')->matched())->toBeTrue()
        ->and($scanner->stats()->dictionaryVersion)->toBe('2');
});

it('does not catch request normalization or matcher errors', function () {
    $repo = new FakeRepository(new SensitiveDictionary('1', []));
    $normalizationScanner = new SensitiveText(new TextNormalizer(), $repo, [new AhoCorasickMatcher()]);
    $normalizationScanner->scan('warmup');
    expect(fn() => $normalizationScanner->scan("\xFF"))
        ->toThrow(NormalizationException::class);

    $failingMatcher = new class implements MatcherInterface {
        public function match(NormalizedText $text, CompiledDictionary $dictionary): array
        {
            throw new InvalidRuleException('runtime regex failure');
        }
    };
    $matcherScanner = new SensitiveText(new TextNormalizer(), $repo, [$failingMatcher]);
    expect(fn() => $matcherScanner->scan('text'))->toThrow(InvalidRuleException::class);
});

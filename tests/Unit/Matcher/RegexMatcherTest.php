<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\InvalidRuleException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Matcher\RegexMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\RegexRule;
use VergilLai\SensitiveText\Rules\RegexTarget;
use VergilLai\SensitiveText\Rules\Severity;

it('maps normalized regex offsets to original text', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize('微❤️信 微信');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));
    $matcher = new RegexMatcher([
        new RegexRule(
            'wx',
            '/微信/u',
            'contact',
            Severity::High,
            Action::Block,
            RegexTarget::Normalized,
            ['source' => 'regex'],
        ),
    ]);

    $matches = $matcher->match($text, $dictionary);

    expect(array_map(
        static fn(MatchResult $match): array => [
            $match->term,
            $match->normalizedTerm,
            $match->matchedText,
            $match->category,
            $match->severity,
            $match->action,
            $match->start,
            $match->end,
            $match->matcher,
            $match->metadata,
        ],
        $matches,
    ))->toBe([
        ['wx', '微信', '微❤️信', 'contact', Severity::High, Action::Block, 0, 4, 'regex', ['source' => 'regex']],
        ['wx', '微信', '微信', 'contact', Severity::High, Action::Block, 5, 7, 'regex', ['source' => 'regex']],
    ]);
});

it('maps original regex byte offsets to codepoint ranges', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize('A中中Ｂ');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

    $matches = (new RegexMatcher([
        new RegexRule('chinese', '/中+/u'),
        new RegexRule('fullwidth', '/Ｂ/u'),
    ]))->match($text, $dictionary);

    expect(array_map(
        static fn(MatchResult $match): array => [
            $match->normalizedTerm,
            $match->matchedText,
            $match->start,
            $match->end,
        ],
        $matches,
    ))->toBe([
        ['中中', '中中', 1, 3],
        ['b', 'Ｂ', 3, 4],
    ]);
});

it('rejects invalid or unsupported regex patterns', function (string $pattern) {
    expect(fn() => new RegexRule('broken', $pattern))->toThrow(InvalidRuleException::class);
})->with([
    'invalid syntax' => '/[/u',
    'unsupported delimiter' => '%微信%u',
    'missing unicode modifier' => '/微信/',
    'unsupported modifier' => '/微信/uz',
]);

it('ignores zero width results and returns no result for a miss', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize('中文');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

    expect((new RegexMatcher([
        new RegexRule('zero', '/(?=中)/u'),
        new RegexRule('miss', '/英文/u'),
    ]))->match($text, $dictionary))->toBe([]);
});

it('raises an explicit exception for runtime pcre errors', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize(str_repeat('a', 100) . '!');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

    expect(fn() => (new RegexMatcher([
        new RegexRule('catastrophic', '/(*NO_JIT)(*LIMIT_MATCH=10)(a+)+$/u'),
    ]))->match($text, $dictionary))->toThrow(InvalidRuleException::class, 'catastrophic');
});

it('keeps preg match all non-overlap semantics within one rule', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize('ababa');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

    $matches = (new RegexMatcher([
        new RegexRule('aba', '/aba/u'),
    ]))->match($text, $dictionary);

    expect(array_map(
        static fn(MatchResult $match): array => [$match->start, $match->end],
        $matches,
    ))->toBe([[0, 3]]);
});

it('preserves overlapping matches from separate regex rules', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize('ababa');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', []));

    $matches = (new RegexMatcher([
        new RegexRule('aba', '~aba~u'),
        new RegexRule('bab', '#bab#u'),
    ]))->match($text, $dictionary);

    expect(array_map(
        static fn(MatchResult $match): array => [$match->term, $match->start, $match->end],
        $matches,
    ))->toBe([
        ['aba', 0, 3],
        ['bab', 1, 4],
    ]);
});

it('preserves regex and aho corasick matches for the same range', function () {
    $normalizer = new TextNormalizer();
    $text = $normalizer->normalize('微信');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('微信'),
    ]));

    $matches = array_merge(
        (new AhoCorasickMatcher())->match($text, $dictionary),
        (new RegexMatcher([new RegexRule('wx', '/微信/u')]))->match($text, $dictionary),
    );

    expect(array_map(
        static fn(MatchResult $match): array => [$match->matcher, $match->start, $match->end],
        $matches,
    ))->toBe([
        ['aho_corasick', 0, 2],
        ['regex', 0, 2],
    ]);
});

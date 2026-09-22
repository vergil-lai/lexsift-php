<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryCompileException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Matcher\WhitelistMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Rules\WhitelistMode;
use VergilLai\SensitiveText\Rules\WhitelistRule;

it('only removes the match contained in a whitelisted phrase', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize('反博彩宣传，参与博彩');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('博彩'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    $filter = new WhitelistMatcher($normalizer, [new WhitelistRule('反博彩宣传')]);
    $kept = $filter->filter($normalized, $matches);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->start)->toBe(8);
});

it('only removes matches whose normalized interval equals an exact whitelist rule', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize('博彩平台');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('博彩'),
        new SensitiveTerm('博彩平台'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    $filter = new WhitelistMatcher($normalizer, [
        new WhitelistRule('博彩', WhitelistMode::Exact),
    ]);
    $kept = $filter->filter($normalized, $matches);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->term)->toBe('博彩平台');
});

it('uses the whole normalized source cluster for exact whitelist rules', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize('ﬃ');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('f'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    $keptByPartialRule = (new WhitelistMatcher($normalizer, [
        new WhitelistRule('f', WhitelistMode::Exact),
    ]))->filter($normalized, $matches);
    $keptByWholeClusterRule = (new WhitelistMatcher($normalizer, [
        new WhitelistRule('ffi', WhitelistMode::Exact),
    ]))->filter($normalized, $matches);

    expect($keptByPartialRule)->toHaveCount(2)
        ->and($keptByWholeClusterRule)->toBe([]);
});

it('keeps dense matches when exact rules are absent', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize(str_repeat('a', 2_000));
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('a'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    expect((new WhitelistMatcher($normalizer, []))->filter($normalized, $matches))->toBe($matches)
        ->and((new WhitelistMatcher($normalizer, [
            new WhitelistRule('unrelated phrase'),
        ]))->filter($normalized, $matches))->toBe($matches);
});

it('keeps a match that only partially overlaps a whitelisted phrase', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize('反博彩平台');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('博彩平台'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    $filter = new WhitelistMatcher($normalizer, [new WhitelistRule('反博彩')]);
    $kept = $filter->filter($normalized, $matches);

    expect($kept)->toHaveCount(1)
        ->and([$kept[0]->start, $kept[0]->end])->toBe([1, 5]);
});

it('handles duplicate and overlapping whitelist phrases without affecting unrelated matches', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize('反博彩平台宣传，毒品');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('平台'),
        new SensitiveTerm('毒品'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    $filter = new WhitelistMatcher($normalizer, [
        new WhitelistRule('反博彩平台宣传'),
        new WhitelistRule('博彩'),
        new WhitelistRule('反博彩平台宣传'),
    ]);
    $kept = $filter->filter($normalized, $matches);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->term)->toBe('毒品')
        ->and([$kept[0]->start, $kept[0]->end])->toBe([8, 10]);
});

it('uses the same normalization when matching whitelist phrases', function () {
    $normalizer = new TextNormalizer();
    $normalized = $normalizer->normalize('反博❤️彩宣传，博彩');
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('博彩'),
    ]));
    $matches = (new AhoCorasickMatcher())->match($normalized, $dictionary);

    $filter = new WhitelistMatcher($normalizer, [new WhitelistRule('反博彩宣传')]);
    $kept = $filter->filter($normalized, $matches);

    expect($kept)->toHaveCount(1)
        ->and([$kept[0]->start, $kept[0]->end])->toBe([8, 10]);
});

it('rejects exact whitelist rules that normalize to empty', function (string $rule) {
    expect(fn() => new WhitelistMatcher(new TextNormalizer(), [
        new WhitelistRule($rule, WhitelistMode::Exact),
    ]))->toThrow(
        DictionaryCompileException::class,
        'Enabled terms must not normalize to an empty string.',
    );
})->with([
    'literal empty' => '',
    'normalized empty' => ' ❤️',
]);

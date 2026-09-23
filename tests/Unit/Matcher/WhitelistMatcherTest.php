<?php

declare(strict_types=1);

use VergilLai\LexSift\Dictionary\DictionaryCompiler;
use VergilLai\LexSift\Matcher;
use VergilLai\LexSift\Matcher\AhoCorasickMatcher;
use VergilLai\LexSift\Normalizer\TextNormalizer;

it('suppresses a match fully contained by an allow phrase', function () {
    $filter = new Matcher(
        terms: ['赌博'],
        whitelist: ['赌博研究'],
    );

    $matches = $filter->scan('赌博研究不是赌博');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['start'])->toBe(18);
});

it('does not suppress a match that extends beyond an allow phrase', function () {
    $filter = new Matcher(
        terms: ['微信号'],
        whitelist: ['微信'],
    );

    expect($filter->contains('微信号'))->toBeTrue();
});

it('returns compact coverage intervals without result objects', function () {
    $normalizer = new TextNormalizer();
    $whitelist = (new DictionaryCompiler($normalizer))->compile(['反赌博', '赌博研究']);

    expect((new AhoCorasickMatcher())->ranges($normalizer->normalize('反赌博研究和赌博'), $whitelist))
        ->toBe([[0, 3], [1, 5]]);
});

it('does not combine phrases to cover a match neither phrase contains', function () {
    $filter = new Matcher(
        terms: ['bc'],
        whitelist: ['ab', 'cd'],
    );

    expect($filter->contains('abcd'))->toBeTrue();
});

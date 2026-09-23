<?php

declare(strict_types=1);

use VergilLai\LexSift\Dictionary\DictionaryCompiler;
use VergilLai\LexSift\Matcher\AhoCorasickMatcher;
use VergilLai\LexSift\Normalizer\NormalizedText;
use VergilLai\LexSift\Normalizer\TextNormalizer;

it('emits nested suffix and overlapping matches with original ranges', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(['赌博', '赌博平台', '平台']);
    $original = '这是赌博平台';

    $matches = (new AhoCorasickMatcher())->match($normalizer->normalize($original), $dictionary);

    expect(array_map(
        static fn(array $match): array => [$match['term'], $match['start'], $match['end']],
        $matches,
    ))->toBe([
        ['赌博', 6, 12],
        ['赌博平台', 6, 18],
        ['平台', 12, 18],
    ]);
});

it('keeps distinct terms that map to the same original unicode range', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(['ff', 'fi']);
    $original = 'xﬃy';

    $matches = (new AhoCorasickMatcher())->match($normalizer->normalize($original), $dictionary);

    expect(array_map(
        static fn(array $match): array => [$match['term'], $match['text'], $match['start'], $match['end']],
        $matches,
    ))->toBe([
        ['ff', 'ﬃ', 1, 4],
        ['fi', 'ﬃ', 1, 4],
    ]);
});

it('deduplicates repeated normalized hits mapped to the same original range', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(['f']);

    $matches = (new AhoCorasickMatcher())->match($normalizer->normalize('ﬃ'), $dictionary);

    expect($matches)->toHaveCount(1)
        ->and([$matches[0]['term'], $matches[0]['text'], $matches[0]['start'], $matches[0]['end']])
        ->toBe(['f', 'ﬃ', 0, 3]);
});

it('stops contains after the first uncovered match', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(['a']);
    $text = new NormalizedText(
        'a',
        'aa',
        ['a', 'a'],
        [0],
        [1],
        [0, 1],
    );

    expect((new AhoCorasickMatcher())->contains($text, $dictionary, []))->toBeTrue();
});

it('does not consume streamed characters after the first output', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(['a']);
    $characters = (static function (): Generator {
        yield 'a';
        throw new RuntimeException('The tail must not be consumed.');
    })();

    expect((new AhoCorasickMatcher())->containsCharacters($characters, $dictionary))->toBeTrue();
});

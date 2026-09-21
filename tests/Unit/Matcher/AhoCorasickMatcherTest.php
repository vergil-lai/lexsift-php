<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Result\ScanResult;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

it('emits nested and suffix matches with original ranges', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('赌博'),
        new SensitiveTerm('赌博平台'),
        new SensitiveTerm('平台'),
    ]));

    $matches = (new AhoCorasickMatcher())->match($normalizer->normalize('这是赌博平台'), $dictionary);
    $result = new ScanResult('这是赌博平台', $matches);

    expect(array_map(
        static fn(MatchResult $match): array => [$match->term, $match->start, $match->end],
        $result->matches(),
    ))->toBe([
        ['赌博', 2, 4],
        ['赌博平台', 2, 6],
        ['平台', 4, 6],
    ])->and($result->mask('*'))->toBe('这是****');
});

it('maps matches to original codepoints including removed emoji', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('微信'),
    ]));
    $original = '请加我微❤️信联系';

    $result = new ScanResult(
        $original,
        (new AhoCorasickMatcher())->match($normalizer->normalize($original), $dictionary),
    );

    expect($result->matches()[0]->matchedText)->toBe('微❤️信')
        ->and([$result->matches()[0]->start, $result->matches()[0]->end])->toBe([3, 7])
        ->and($result->mask())->toBe('请加我****联系');
});

it('matches regression cases', function (array $terms, string $original, array $expected) {
    $normalizer = new TextNormalizer();
    $sensitiveTerms = [];
    foreach ($terms as $term) {
        if (!is_string($term)) {
            throw new LogicException('Regression terms must be strings.');
        }
        $sensitiveTerms[] = new SensitiveTerm($term);
    }
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary(
        '1',
        $sensitiveTerms,
    ));
    $result = new ScanResult(
        $original,
        (new AhoCorasickMatcher())->match($normalizer->normalize($original), $dictionary),
    );

    expect(array_map(
        static fn(MatchResult $match): array => [$match->term, $match->matchedText, $match->start, $match->end],
        $result->matches(),
    ))->toBe($expected);
})->with([
    'no match' => [
        ['赌博'],
        '完全正常',
        [],
    ],
    'empty dictionary' => [
        [],
        'anything',
        [],
    ],
    'classic suffix set' => [
        ['he', 'she', 'hers', 'his'],
        'ushers his',
        [
            ['she', 'she', 1, 4],
            ['he', 'he', 2, 4],
            ['hers', 'hers', 2, 6],
            ['his', 'his', 7, 10],
        ],
    ],
    'repeated prefixes' => [
        ['a', 'aa', 'aaa'],
        'aaa',
        [
            ['a', 'a', 0, 1],
            ['aa', 'aa', 0, 2],
            ['aaa', 'aaa', 0, 3],
            ['a', 'a', 1, 2],
            ['aa', 'aa', 1, 3],
            ['a', 'a', 2, 3],
        ],
    ],
    'mixed chinese english and digits' => [
        ['敏感abc123', 'abc', '123'],
        'XX敏感ABC123YY',
        [
            ['敏感abc123', '敏感ABC123', 2, 10],
            ['abc', 'ABC', 4, 7],
            ['123', '123', 7, 10],
        ],
    ],
    'single match' => [
        ['foo'],
        'xxFOOyy',
        [
            ['foo', 'FOO', 2, 5],
        ],
    ],
]);

it('preserves matches for the same normalized term with different policies', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('ABC', 'low', Severity::Low, Action::Flag, metadata: ['source' => 'first']),
        new SensitiveTerm('abc', 'high', Severity::Critical, Action::Block, metadata: ['source' => 'second']),
    ]));
    $result = new ScanResult(
        'AbC',
        (new AhoCorasickMatcher())->match($normalizer->normalize('AbC'), $dictionary),
    );

    expect(array_map(
        static fn(MatchResult $match): array => [
            $match->term,
            $match->category,
            $match->severity,
            $match->action,
            $match->metadata,
        ],
        $result->matches(),
    ))->toBe([
        ['ABC', 'low', Severity::Low, Action::Flag, ['source' => 'first']],
        ['abc', 'high', Severity::Critical, Action::Block, ['source' => 'second']],
    ]);
});

it('masks one original ligature once for overlapping normalized matches', function () {
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('1', [
        new SensitiveTerm('ff'),
        new SensitiveTerm('fi'),
    ]));
    $result = new ScanResult(
        'xﬃy',
        (new AhoCorasickMatcher())->match($normalizer->normalize('xﬃy'), $dictionary),
    );

    expect(array_map(
        static fn(MatchResult $match): array => [$match->term, $match->matchedText, $match->start, $match->end],
        $result->matches(),
    ))->toBe([
        ['ff', 'ﬃ', 1, 2],
        ['fi', 'ﬃ', 1, 2],
    ])->and($result->mask())->toBe('x*y');
});

it('agrees with a fixed seed naive matcher', function () {
    $randomizer = new Random\Randomizer(new Random\Engine\Mt19937(20260921));
    $alphabet = ['a', 'b', '中', '1'];
    $makeString = static function (int $length) use ($randomizer, $alphabet): string {
        $value = '';
        for ($index = 0; $index < $length; ++$index) {
            $value .= $alphabet[$randomizer->getInt(0, count($alphabet) - 1)];
        }

        return $value;
    };

    $terms = [];
    for ($index = 0; $index < 20; ++$index) {
        $terms[] = new SensitiveTerm($makeString($randomizer->getInt(1, 4)));
    }
    $original = $makeString(120);
    $normalizer = new TextNormalizer();
    $dictionary = (new DictionaryCompiler($normalizer))->compile(new SensitiveDictionary('seed', $terms));
    $normalized = $normalizer->normalize($original);

    $signature = static fn(MatchResult $match): string => json_encode([
        $match->term,
        $match->normalizedTerm,
        $match->matchedText,
        $match->category,
        $match->severity->value,
        $match->action->value,
        $match->start,
        $match->end,
        $match->matcher,
        $match->metadata,
    ], JSON_THROW_ON_ERROR);
    $actual = array_map(
        $signature,
        (new AhoCorasickMatcher())->match($normalized, $dictionary),
    );

    $expected = [];
    foreach ($normalized->characters as $start => $_character) {
        foreach ($dictionary->normalizedTerms as $termId => $normalizedTerm) {
            $length = $dictionary->termLengths[$termId];
            if ($normalizedTerm !== implode('', array_slice($normalized->characters, $start, $length))) {
                continue;
            }

            $span = $normalized->span($start, $start + $length);
            $term = $dictionary->terms[$termId];
            $expected[] = $signature(new MatchResult(
                $term->term,
                $normalizedTerm,
                $normalized->sliceOriginal($span),
                $term->category,
                $term->severity,
                $term->action,
                $span->start,
                $span->end,
                'aho_corasick',
                $term->metadata,
            ));
        }
    }

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

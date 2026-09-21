<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryCompileException;
use VergilLai\SensitiveText\Exception\NormalizationException;
use VergilLai\SensitiveText\Matcher\AhoCorasickCompiler;
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Rules\Severity;

it('deduplicates identical records but preserves different policies', function () {
    $a = new SensitiveTerm('WeChat');
    $b = new SensitiveTerm('wechat', severity: Severity::High);

    $dictionary = (new DictionaryCompiler(new TextNormalizer()))
        ->compile(new SensitiveDictionary('1', [$a, $a, $b]));

    expect($dictionary->normalizedTerms)->toBe(['wechat', 'wechat'])
        ->and($dictionary->termLengths)->toBe([6, 6]);
});

it('canonicalizes metadata for deduplication and preserves different metadata', function () {
    $first = new SensitiveTerm('term', metadata: [
        'source' => 'manual',
        'options' => ['note' => null, 'exact' => true],
    ]);
    $reordered = new SensitiveTerm('term', metadata: [
        'options' => ['exact' => true, 'note' => null],
        'source' => 'manual',
    ]);
    $different = new SensitiveTerm('term', metadata: ['source' => 'imported']);

    $dictionary = (new DictionaryCompiler(new TextNormalizer()))
        ->compile(new SensitiveDictionary('1', [$first, $reordered, $different]));

    expect($dictionary->terms)->toBe([$first, $different])
        ->and($dictionary->normalizedTerms)->toBe(['term', 'term']);
});

it('uses the injected normalizer configuration', function () {
    $normalizer = new TextNormalizer(new NormalizerConfig(removeWhitespace: false));

    $dictionary = (new DictionaryCompiler($normalizer))
        ->compile(new SensitiveDictionary('1', [new SensitiveTerm('A B')]));

    expect($dictionary->normalizedTerms)->toBe(['a b'])
        ->and($dictionary->termLengths)->toBe([3]);
});

it('rejects enabled terms normalized to empty', function () {
    expect(fn() => (new DictionaryCompiler(new TextNormalizer()))
        ->compile(new SensitiveDictionary('1', [new SensitiveTerm('❤️')])))
        ->toThrow(DictionaryCompileException::class);
});

it('supports an intentionally empty dictionary', function () {
    $dictionary = (new DictionaryCompiler(new TextNormalizer()))
        ->compile(new SensitiveDictionary('1', []));

    expect($dictionary->transitions)->toBe([[]])
        ->and($dictionary->failures)->toBe([0]);
});

it('skips disabled terms before normalization', function () {
    $dictionary = (new DictionaryCompiler(new TextNormalizer()))
        ->compile(new SensitiveDictionary('1', [new SensitiveTerm("\xFF", enabled: false)]));

    expect($dictionary->terms)->toBe([])
        ->and($dictionary->normalizedTerms)->toBe([])
        ->and($dictionary->transitions)->toBe([[]]);
});

it('wraps invalid utf8 failures and preserves the cause', function () {
    try {
        (new DictionaryCompiler(new TextNormalizer()))
            ->compile(new SensitiveDictionary('1', [new SensitiveTerm("\xFF")]));
    } catch (DictionaryCompileException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(NormalizationException::class);

        return;
    }

    throw new RuntimeException('Expected dictionary compilation to fail.');
});

it('prefixes numeric transition keys', function () {
    $tables = (new AhoCorasickCompiler())->compile(['123']);

    expect(array_keys($tables['transitions'][0]))->toBe(['u:1'])
        ->and(array_keys($tables['transitions'][1]))->toBe(['u:2'])
        ->and(array_keys($tables['transitions'][2]))->toBe(['u:3']);
});

it('links suffix matches without copying outputs', function () {
    $tables = (new AhoCorasickCompiler())->compile(['he', 'she', 'hers', 'his']);
    $stateFor = static function (string $term) use ($tables): int {
        $state = 0;
        foreach (mb_str_split($term, 1, 'UTF-8') as $character) {
            $state = $tables['transitions'][$state]['u:' . $character];
        }

        return $state;
    };

    $he = $stateFor('he');
    $she = $stateFor('she');

    expect($tables['failures'][$she])->toBe($he)
        ->and($tables['outputs'][$he])->toBe([0])
        ->and($tables['outputs'][$she])->toBe([1])
        ->and($tables['outputLinks'][$she])->toBe($he);
});

it('stores only direct outputs for suffix chains', function () {
    $tables = (new AhoCorasickCompiler())->compile(['a', 'aa', 'aaa']);
    $a = $tables['transitions'][0]['u:a'];
    $aa = $tables['transitions'][$a]['u:a'];
    $aaa = $tables['transitions'][$aa]['u:a'];

    expect($tables['outputs'][$a])->toBe([0])
        ->and($tables['outputs'][$aa])->toBe([1])
        ->and($tables['outputs'][$aaa])->toBe([2])
        ->and(array_sum(array_map('count', $tables['outputs'])))->toBe(3)
        ->and($tables['outputLinks'][$aa])->toBe($a)
        ->and($tables['outputLinks'][$aaa])->toBe($aa);
});

it('inherits the nearest output link through a non-output failure node', function () {
    $tables = (new AhoCorasickCompiler())->compile(['b', 'abx', 'zab']);
    $b = $tables['transitions'][0]['u:b'];
    $z = $tables['transitions'][0]['u:z'];
    $za = $tables['transitions'][$z]['u:a'];
    $zab = $tables['transitions'][$za]['u:b'];

    expect($tables['outputs'][$tables['failures'][$zab]])->toBe([])
        ->and($tables['outputLinks'][$zab])->toBe($b);
});

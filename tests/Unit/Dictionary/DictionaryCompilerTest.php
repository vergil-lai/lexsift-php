<?php

declare(strict_types=1);

use VergilLai\LexSift\Dictionary\DictionaryCompiler;
use VergilLai\LexSift\Normalizer\TextNormalizer;

it('compiles strings and keeps the first original term after normalization deduplication', function () {
    $compiled = (new DictionaryCompiler(new TextNormalizer()))->compile(['ＡＢＣ', 'abc', '赌博']);

    expect($compiled->terms)->toBe(['ＡＢＣ', '赌博'])
        ->and($compiled->normalizedTerms)->toBe(['abc', '赌博']);
});

it('deduplicates every normalization collision while preserving the first spelling', function () {
    $compiled = (new DictionaryCompiler(new TextNormalizer()))->compile([
        '微 信',
        '微信',
        '微❤️信',
        'ＦＦＩ',
        'ﬃ',
        'ffi',
    ]);

    expect($compiled->terms)->toBe(['微 信', 'ＦＦＩ'])
        ->and($compiled->normalizedTerms)->toBe(['微信', 'ffi']);
});

it('stores unicode characters directly as transition keys', function () {
    $compiled = (new DictionaryCompiler(new TextNormalizer()))->compile(['微信']);

    expect($compiled->transitions[0])->toHaveKey('微')
        ->and($compiled->transitions[0])->not->toHaveKey('u:微');
});

it('accepts an empty list', function () {
    $compiled = (new DictionaryCompiler(new TextNormalizer()))->compile([]);

    expect($compiled->terms)->toBe([])
        ->and($compiled->transitions)->toBe([[]]);
});

it('accepts associative arrays and ignores keys', function () {
    $compiled = (new DictionaryCompiler(new TextNormalizer()))->compile(['term' => '赌博']);
    expect($compiled->terms)->toBe(['赌博']);
});

it('rejects non-string terms', function () {
    (new DictionaryCompiler(new TextNormalizer()))->compile(['赌博', 123]);
})->throws(TypeError::class);

it('rejects empty strings', function () {
    (new DictionaryCompiler(new TextNormalizer()))->compile(['']);
})->throws(\ValueError::class);

it('rejects invalid UTF-8 strings', function () {
    (new DictionaryCompiler(new TextNormalizer()))->compile(["\xFF"]);
})->throws(\ValueError::class);

it('rejects terms that normalize to an empty string', function () {
    (new DictionaryCompiler(new TextNormalizer()))->compile(['❤️']);
})->throws(\ValueError::class);

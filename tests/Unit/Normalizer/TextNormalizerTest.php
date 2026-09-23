<?php

declare(strict_types=1);

use VergilLai\LexSift\Normalizer\TextNormalizer;

dataset('normalization', [
    ['ＷＥＣＨＡＴ', 'wechat'],
    ['WeChat', 'wechat'],
    ['微 信', '微信'],
    ['微❤️信', '微信'],
    ['C++ C# foo.bar', 'c++c#foo.bar'],
    ["e\u{0301}", 'é'],
    ['ﬃ', 'ffi'],
    ["\u{1100}\u{1161}", '가'],
    ['ΟΣ', 'οσ'],
    ['', ''],
    [" \t\n❤️", ''],
    ['１２3', '123'],
    ['微👨‍👩‍👧‍👦🇨🇳1️⃣信', '微信'],
    ['中English文', '中english文'],
]);

it('normalizes deterministically', function (string $input, string $output) {
    expect((new TextNormalizer())->normalize($input)->normalized)->toBe($output);
})->with('normalization');

it('uses the same transformation rules for strings without source mapping', function (string $input) {
    $normalizer = new TextNormalizer();

    expect($normalizer->normalizeString($input))
        ->toBe($normalizer->normalize($input)->normalized);
})->with([
    'compatibility and lowercase' => ['ＷeＣhＡt'],
    'combining marks' => ["a\u{0315}\u{0300}"],
    'ligature expansion' => ['ﬃ'],
    'emoji and whitespace removal' => [' 微❤️ 信 '],
    'hangul composition' => ["\u{1100}\u{1161}\u{11A8}"],
]);

it('streams the same normalized characters without building source mapping', function (string $input) {
    $normalizer = new TextNormalizer();

    expect(iterator_to_array($normalizer->characters($input), false))
        ->toBe($normalizer->normalize($input)->characters);
})->with([
    'ascii' => ['A b C'],
    'unicode' => ['微❤️信 ﬃ'],
    'combining marks' => ["e\u{0301}"],
]);

it('rejects invalid utf8', function () {
    expect(fn() => (new TextNormalizer())->normalize("\xFF"))
        ->toThrow(\ValueError::class);
});

it('agrees with whole-string ICU normalization', function () {
    $normalizer = new TextNormalizer([
        'lowercase' => false,
        'remove_whitespace' => false,
        'remove_emoji' => false,
    ]);

    foreach (["a\u{0315}\u{0300}", "\u{1100}\u{1161}\u{11A8}", 'ｶﾞ', '㍍ﬃ', 'Å'] as $text) {
        expect($normalizer->normalize($text)->normalized)
            ->toBe(Normalizer::normalize($text, Normalizer::FORM_KC));
    }
});

it('applies each removal option independently', function (array $config, string $input, string $output) {
    expect((new TextNormalizer($config))->normalize($input)->normalized)->toBe($output);
})->with([
    'keeps whitespace when disabled' => [
        ['remove_whitespace' => false],
        ' A B ',
        ' a b ',
    ],
    'removes fullwidth punctuation' => [
        ['remove_punctuation' => true],
        'Ａ，B。!',
        'ab',
    ],
    'removes symbols' => [
        ['remove_symbols' => true],
        'C++ ¥',
        'c',
    ],
    'keeps emoji when disabled' => [
        ['remove_emoji' => false],
        '微😀👨‍👩‍👧‍👦🇨🇳1️⃣信',
        '微😀👨‍👩‍👧‍👦🇨🇳1️⃣信',
    ],
]);

it('removes multiple emoji clusters', function () {
    expect((new TextNormalizer())->normalize('🏽😀中🚀文❤️')->normalized)->toBe('中文');
});

it('keeps every character when removal options are disabled', function () {
    $normalizer = new TextNormalizer([
        'lowercase' => false,
        'remove_whitespace' => false,
        'remove_punctuation' => false,
        'remove_symbols' => false,
        'remove_emoji' => false,
    ]);

    expect($normalizer->normalize('A ❤️ +.!')->normalized)->toBe('A ❤️ +.!');
});

it('can bypass unicode normalization while retaining other behavior', function () {
    $normalizer = new TextNormalizer([
        'unicode_nfkc' => false,
        'lowercase' => false,
        'remove_whitespace' => false,
        'remove_emoji' => false,
    ]);

    expect($normalizer->normalize("ｅ\u{0301}")->normalized)->toBe("ｅ\u{0301}");
});

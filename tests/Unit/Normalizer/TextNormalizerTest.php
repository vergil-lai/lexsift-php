<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Exception\NormalizationException;
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;

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

it('rejects invalid utf8', function () {
    expect(fn() => (new TextNormalizer())->normalize("\xFF"))
        ->toThrow(NormalizationException::class);
});

it('agrees with whole-string ICU normalization', function () {
    $normalizer = new TextNormalizer(new NormalizerConfig(
        lowercase: false,
        removeWhitespace: false,
        removeEmoji: false,
    ));

    foreach (["a\u{0315}\u{0300}", "\u{1100}\u{1161}\u{11A8}", 'ｶﾞ', '㍍ﬃ', 'Å'] as $text) {
        expect($normalizer->normalize($text)->normalized)
            ->toBe(Normalizer::normalize($text, Normalizer::FORM_KC));
    }
});

it('applies each removal option independently', function (NormalizerConfig $config, string $input, string $output) {
    expect((new TextNormalizer($config))->normalize($input)->normalized)->toBe($output);
})->with([
    'keeps whitespace when disabled' => [
        new NormalizerConfig(removeWhitespace: false),
        ' A B ',
        ' a b ',
    ],
    'removes fullwidth punctuation' => [
        new NormalizerConfig(removePunctuation: true),
        'Ａ，B。!',
        'ab',
    ],
    'removes symbols' => [
        new NormalizerConfig(removeSymbols: true),
        'C++ ¥',
        'c',
    ],
    'keeps emoji when disabled' => [
        new NormalizerConfig(removeEmoji: false),
        '微😀👨‍👩‍👧‍👦🇨🇳1️⃣信',
        '微😀👨‍👩‍👧‍👦🇨🇳1️⃣信',
    ],
    'removes configured characters after lowercase' => [
        new NormalizerConfig(removeCharacters: ['x']),
        'xX中',
        '中',
    ],
]);

it('removes multiple emoji clusters', function () {
    expect((new TextNormalizer())->normalize('🏽😀中🚀文❤️')->normalized)->toBe('中文');
});

it('keeps every character when removal options are disabled', function () {
    $normalizer = new TextNormalizer(new NormalizerConfig(
        lowercase: false,
        removeWhitespace: false,
        removePunctuation: false,
        removeSymbols: false,
        removeEmoji: false,
    ));

    expect($normalizer->normalize('A ❤️ +.!')->normalized)->toBe('A ❤️ +.!');
});

it('can bypass unicode normalization while retaining other behavior', function () {
    $normalizer = new TextNormalizer(new NormalizerConfig(
        unicodeNfkc: false,
        lowercase: false,
        removeWhitespace: false,
        removeEmoji: false,
    ));

    expect($normalizer->normalize("ｅ\u{0301}")->normalized)->toBe("ｅ\u{0301}");
});

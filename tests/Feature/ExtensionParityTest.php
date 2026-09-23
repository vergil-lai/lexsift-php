<?php

declare(strict_types=1);

use VergilLai\LexSift\Matcher;

test('retained CRLF maps to the complete source grapheme', function (): void {
    $matcher = new Matcher(["\r", "\n"], options: ['remove_whitespace' => false]);
    expect($matcher->scan("x\r\ny"))->toBe([
        ['term' => "\r", 'text' => "\r\n", 'start' => 1, 'end' => 3],
        ['term' => "\n", 'text' => "\r\n", 'start' => 1, 'end' => 3],
    ])->and($matcher->mask("x\r\ny"))->toBe('x*y');
});

test('matches the installed extension across all normalization options', function (): void {
    if (!class_exists('LexSift\\Matcher')) {
        PHPUnit\Framework\TestCase::markTestSkipped('LexSift extension is optional for differential tests.');
    }

    $keys = ['unicode_nfkc', 'lowercase', 'remove_whitespace', 'remove_punctuation', 'remove_symbols', 'remove_emoji'];
    $terms = ['a', 'A', 'ab', 'abc', 'bc', 'f', 'ffi', 'i', 'à', 'é', '가', 'ᄀ', '赌博', '微信', "i\u{0307}", 'σ', 'ς'];
    $texts = [
        "x\r\ny", '前赌 博后，微信支付', 'ﬃﬁ', "a\u{0315}\u{0300}", "e\u{0301}",
        'ﾡￂ', 'ﾡ👨‍👩‍👧‍👦ￂ', 'ＡＢＣ abc', 'İΟΣ', "a\0b", '赌1️⃣🇨🇳👍🏽博',
        "a\u{0301}😀\u{0327}b", 'ab-cd abc', "à\u{0315}", '赌$博 微信',
    ];
    for ($bits = 0; $bits < 64; ++$bits) {
        $options = [];
        foreach ($keys as $bit => $key) {
            $options[$key] = 0 !== ($bits & (1 << $bit));
        }
        foreach ([[], ['f', '微信支付', 'ab']] as $whitelist) {
            $php = new Matcher($terms, $whitelist, $options);
            $extension = new \LexSift\Matcher($terms, $whitelist, $options);
            foreach ($texts as $text) {
                expect($php->scan($text))->toBe($extension->scan($text))
                    ->and($php->contains($text))->toBe($extension->contains($text))
                    ->and($php->mask($text, '[x]'))->toBe($extension->mask($text, '[x]'));
            }
        }
    }
});

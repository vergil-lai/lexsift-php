<?php

declare(strict_types=1);

use VergilLai\LexSift\Normalizer\TextNormalizer;

it('keeps original intervals through expansion and deletion', function () {
    $normalized = (new TextNormalizer())->normalize('请加我微❤️信联系');
    $span = $normalized->span(3, 5);

    expect($span)->toBe([3, 7])
        ->and($normalized->sliceOriginal($span))->toBe('微❤️信');

    $normalized = (new TextNormalizer())->normalize('ﬃ');

    expect($normalized->sliceOriginal($normalized->span(1, 2)))->toBe('ﬃ');
});

it('does not absorb removed edges', function () {
    $normalized = (new TextNormalizer(['remove_punctuation' => true, 'remove_symbols' => true]))
        ->normalize(' ❤️微---信❤️ ');

    expect($normalized->sliceOriginal($normalized->span(0, 2)))->toBe('微---信');
});

it('maps every expanded character to its source cluster', function () {
    $normalized = (new TextNormalizer())->normalize('ﬃİ');

    expect($normalized->normalized)->toBe("ffii\u{0307}")
        ->and(array_map(
            static fn(int $start, int $end): array => [$start, $end],
            $normalized->sourceStarts,
            $normalized->sourceEnds,
        ))->toBe([
            [0, 1],
            [0, 1],
            [0, 1],
            [1, 2],
            [1, 2],
        ]);
});

it('returns normalized characters intersecting an original interval', function () {
    $normalized = (new TextNormalizer())->normalize("e\u{0301} ﬃ❤️中");

    expect($normalized->normalizedRangeForOriginal(1, 2))->toBe('é')
        ->and($normalized->normalizedRangeForOriginal(3, 4))->toBe('ffi')
        ->and($normalized->normalizedRangeForOriginal(4, 6))->toBe('')
        ->and($normalized->normalizedRangeForOriginal(3, 7))->toBe('ffi中');
});

it('looks up a narrow original range without scanning the full normalized text', function () {
    $measure = static function (int $size): int {
        $characters = array_fill(0, $size, 'a');
        $offsetMap = [];
        for ($index = 0; $index < $size; ++$index) {
            $offsetMap[] = [$index, $index + 1];
        }

        $normalized = new \VergilLai\LexSift\Normalizer\NormalizedText(
            str_repeat('a', $size),
            str_repeat('a', $size),
            $characters,
            array_map(static fn(array $span): int => $span[0], $offsetMap),
            array_map(static fn(array $span): int => $span[1], $offsetMap),
            range(0, $size),
        );
        expect($normalized->normalizedRangeForOriginal($size - 1, $size))->toBe('a');

        $startedAt = hrtime(true);
        for ($query = 0; $query < 2_000; ++$query) {
            $normalized->normalizedRangeForOriginal($size - 1, $size);
        }

        return hrtime(true) - $startedAt;
    };

    $small = $measure(1_000);
    $large = $measure(4_000);

    expect($large / max(1, $small))->toBeLessThan(2.5);
});

it('maps every unnormalized code point to its complete source cluster', function () {
    $normalized = (new TextNormalizer([
        'unicode_nfkc' => false,
        'lowercase' => false,
        'remove_whitespace' => false,
        'remove_emoji' => false,
    ]))->normalize("a\u{0315}");

    expect(array_map(
        static fn(int $start, int $end): array => [$start, $end],
        $normalized->sourceStarts,
        $normalized->sourceEnds,
    ))->toBe([
        [0, 2],
        [0, 2],
    ]);
});

it('stores byte offsets for original code point boundaries', function () {
    $normalized = (new TextNormalizer())->normalize('A微❤️');

    expect($normalized->originalByteOffsets)->toBe([0, 1, 4, 7, 10]);
});

it('rejects invalid normalized and original intervals', function () {
    $normalized = (new TextNormalizer())->normalize('文本');

    expect(fn() => $normalized->span(0, 0))->toThrow(\ValueError::class)
        ->and(fn() => $normalized->span(-1, 1))->toThrow(\ValueError::class)
        ->and(fn() => $normalized->span(0, 3))->toThrow(\ValueError::class)
        ->and(fn() => $normalized->sliceOriginal([0, 3]))
        ->toThrow(\ValueError::class)
        ->and(fn() => $normalized->normalizedRangeForOriginal(1, 1))
        ->toThrow(\ValueError::class);
});

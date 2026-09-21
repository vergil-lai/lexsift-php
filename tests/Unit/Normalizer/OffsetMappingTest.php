<?php

declare(strict_types=1);

use VergilLai\SensitiveText\Exception\NormalizationException;
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\Normalizer\SourceSpan;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;

it('keeps original intervals through expansion and deletion', function () {
    $normalized = (new TextNormalizer())->normalize('请加我微❤️信联系');
    $span = $normalized->span(3, 5);

    expect([$span->start, $span->end])->toBe([3, 7])
        ->and($normalized->sliceOriginal($span))->toBe('微❤️信');

    $normalized = (new TextNormalizer())->normalize('ﬃ');

    expect($normalized->sliceOriginal($normalized->span(1, 2)))->toBe('ﬃ');
});

it('does not absorb removed edges', function () {
    $normalized = (new TextNormalizer(NormalizerConfig::aggressive()))
        ->normalize(' ❤️微---信❤️ ');

    expect($normalized->sliceOriginal($normalized->span(0, 2)))->toBe('微---信');
});

it('maps every expanded character to its source cluster', function () {
    $normalized = (new TextNormalizer())->normalize('ﬃİ');

    expect($normalized->normalized)->toBe("ffii\u{0307}")
        ->and(array_map(
            static fn(SourceSpan $span): array => [$span->start, $span->end],
            $normalized->offsetMap,
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

it('stores byte offsets for original code point boundaries', function () {
    $normalized = (new TextNormalizer())->normalize('A微❤️');

    expect($normalized->originalByteOffsets)->toBe([0, 1, 4, 7, 10]);
});

it('rejects invalid normalized and original intervals', function () {
    $normalized = (new TextNormalizer())->normalize('文本');

    expect(fn() => $normalized->span(0, 0))->toThrow(NormalizationException::class)
        ->and(fn() => $normalized->span(-1, 1))->toThrow(NormalizationException::class)
        ->and(fn() => $normalized->span(0, 3))->toThrow(NormalizationException::class)
        ->and(fn() => $normalized->sliceOriginal(new SourceSpan(0, 3)))
        ->toThrow(NormalizationException::class)
        ->and(fn() => $normalized->normalizedRangeForOriginal(1, 1))
        ->toThrow(NormalizationException::class);
});

<?php

declare(strict_types=1);

namespace VergilLai\LexSift\Normalizer;

/**
 * 保存归一化文本，以及归一化码点到原始码点和字节偏移的 packed 映射。
 *
 * @internal
 */
final readonly class NormalizedText
{
    /**
     * @param string           $original            原始 UTF-8 文本
     * @param string           $normalized          归一化后的文本
     * @param list<string> $characters
     * @param list<int>    $sourceStarts        每个归一化码点对应的原文起点
     * @param list<int>    $sourceEnds          每个归一化码点对应的原文终点
     * @param list<int>    $originalByteOffsets 原始码点边界对应的字节偏移，包含结尾哨兵
     */
    public function __construct(
        public string $original,
        public string $normalized,
        public array $characters,
        public array $sourceStarts,
        public array $sourceEnds,
        public array $originalByteOffsets,
    ) {}

    /**
     * 将归一化文本的半开区间合并映射为覆盖完整来源 grapheme 的原始区间。
     *
     * @return array{int, int}
     *
     * @throws \ValueError 区间为空或越界
     */
    public function span(int $start, int $end): array
    {
        if ($start < 0 || $end <= $start || $end > count($this->sourceStarts)) {
            throw new \ValueError('Invalid normalized interval');
        }

        $spanStart = $this->sourceStarts[$start];
        $spanEnd = $this->sourceEnds[$start];
        for ($index = $start + 1; $index < $end; ++$index) {
            $spanStart = min($spanStart, $this->sourceStarts[$index]);
            $spanEnd = max($spanEnd, $this->sourceEnds[$index]);
        }

        return [$spanStart, $spanEnd];
    }

    /**
     * 按原始码点区间提取精确 UTF-8 字节切片。
     *
     * @param array{int, int} $span
     *
     * @throws \ValueError 原始区间为空或越界
     */
    public function sliceOriginal(array $span): string
    {
        if ($span[0] < 0 || $span[1] <= $span[0] || $span[1] >= count($this->originalByteOffsets)) {
            throw new \ValueError('Invalid original interval');
        }

        return substr(
            $this->original,
            $this->originalByteOffsets[$span[0]],
            $this->originalByteOffsets[$span[1]] - $this->originalByteOffsets[$span[0]],
        );
    }

    /**
     * 返回与原始半开区间相交的归一化字符。
     *
     * @throws \ValueError 原始区间为空或越界
     */
    public function normalizedRangeForOriginal(int $start, int $end): string
    {
        if ($start < 0 || $end <= $start || $end >= count($this->originalByteOffsets)) {
            throw new \ValueError('Invalid original interval');
        }

        // packed 映射按来源区间单调排列，两个二分分别寻找首个相交项和右侧边界。
        $left = 0;
        $right = count($this->sourceStarts);
        while ($left < $right) {
            $mid = intdiv($left + $right, 2);
            if ($this->sourceEnds[$mid] <= $start) {
                $left = $mid + 1;
            } else {
                $right = $mid;
            }
        }
        $rangeStart = $left;

        $right = count($this->sourceStarts);
        while ($left < $right) {
            $mid = intdiv($left + $right, 2);
            if ($this->sourceStarts[$mid] < $end) {
                $left = $mid + 1;
            } else {
                $right = $mid;
            }
        }

        return implode('', array_slice($this->characters, $rangeStart, $left - $rangeStart));
    }
}

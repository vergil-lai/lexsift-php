<?php

declare(strict_types=1);

namespace VergilLai\LexSift\Matcher;

use VergilLai\LexSift\Dictionary\CompiledDictionary;
use VergilLai\LexSift\Normalizer\NormalizedText;

/**
 * 在已编译词库上执行线性的 Aho-Corasick 多模式匹配。
 *
 * @internal
 */
final class AhoCorasickMatcher
{
    /**
     * 返回包括重叠词条和后缀词条在内的全部命中。
     *
     * @param list<array{0: int, 1: int}> $coveredRanges
     *
     * @return list<array{term: string, text: string, start: int, end: int}>
     */
    public function match(NormalizedText $text, CompiledDictionary $dictionary, array $coveredRanges = []): array
    {
        $matches = [];
        $seen = [];

        foreach ($this->occurrences($text, $dictionary) as [$termId, $normalizedSpan]) {
            if ($this->isCovered($normalizedSpan, $coveredRanges)) {
                continue;
            }
            $span = $text->span($normalizedSpan[0], $normalizedSpan[1]);

            // 一个兼容字符展开后可能重复命中同一词条和原文区间，只保留第一条。
            $identity = $termId . ':' . $span[0] . ':' . $span[1];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $start = $text->originalByteOffsets[$span[0]];
            $end = $text->originalByteOffsets[$span[1]];
            $matches[] = [
                'term' => $dictionary->terms[$termId],
                'text' => substr($text->original, $start, $end - $start),
                'start' => $start,
                'end' => $end,
            ];
        }

        return $matches;
    }

    /**
     * 在首个未被覆盖的命中处立即返回。
     *
     * @param list<array{0: int, 1: int}> $coveredRanges
     */
    public function contains(NormalizedText $text, CompiledDictionary $dictionary, array $coveredRanges): bool
    {
        foreach ($this->occurrences($text, $dictionary) as [, $span]) {
            if (!$this->isCovered($span, $coveredRanges)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 在流式字符中遇到首个自动机输出时立即返回。
     *
     * @param iterable<string> $characters
     */
    public function containsCharacters(iterable $characters, CompiledDictionary $dictionary): bool
    {
        $state = 0;
        foreach ($characters as $character) {
            while (0 !== $state && !isset($dictionary->transitions[$state][$character])) {
                $state = $dictionary->failures[$state] ?? 0;
            }

            $state = $dictionary->transitions[$state][$character] ?? 0;
            if ([] !== $dictionary->outputs[$state] || null !== $dictionary->outputLinks[$state]) {
                return true;
            }
        }

        return false;
    }

    /**
     * 返回去重、排序并移除冗余后的归一化文本覆盖区间。
     *
     * @return list<array{0: int, 1: int}>
     */
    public function ranges(NormalizedText $text, CompiledDictionary $dictionary): array
    {
        if ([] === $dictionary->terms) {
            return [];
        }

        $ranges = [];
        $seen = [];
        foreach ($this->occurrences($text, $dictionary) as [, $span]) {
            $identity = $span[0] . ':' . $span[1];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $ranges[] = [$span[0], $span[1]];
        }

        usort($ranges, static fn(array $left, array $right): int => [
            $left[0],
            -$left[1],
        ] <=> [
            $right[0],
            -$right[1],
        ]);

        $compacted = [];
        $furthestEnd = -1;
        foreach ($ranges as [$start, $end]) {
            // 被此前单个区间完整包含的区间不会改变覆盖判断，可以直接丢弃。
            if ($end <= $furthestEnd) {
                continue;
            }
            $compacted[] = [$start, $end];
            $furthestEnd = $end;
        }

        return $compacted;
    }

    /**
     * 逐个产生词条编号及其归一化区间，调用方可随时停止遍历。
     *
     * @return \Generator<int, array{0: int, 1: array{int, int}}>
     */
    private function occurrences(NormalizedText $text, CompiledDictionary $dictionary): \Generator
    {
        $state = 0;

        foreach ($text->characters as $index => $character) {
            while (0 !== $state && !isset($dictionary->transitions[$state][$character])) {
                $state = $dictionary->failures[$state] ?? 0;
            }

            $state = $dictionary->transitions[$state][$character] ?? 0;
            // 沿输出链收集失败路径上的后缀词条，避免复制输出集合。
            for ($node = $state; null !== $node; $node = $dictionary->outputLinks[$node]) {
                foreach ($dictionary->outputs[$node] as $termId) {
                    $span = [
                        $index + 1 - $dictionary->termLengths[$termId],
                        $index + 1,
                    ];
                    yield [$termId, $span];
                }
            }
        }
    }

    /**
     * 判断一个命中区间是否被已压缩的允许区间完整覆盖。
     *
     * @param array{int, int} $span
     * @param list<array{0: int, 1: int}> $coveredRanges
     */
    private function isCovered(array $span, array $coveredRanges): bool
    {
        $left = 0;
        $right = count($coveredRanges);
        while ($left < $right) {
            $middle = intdiv($left + $right, 2);
            if ($coveredRanges[$middle][0] <= $span[0]) {
                $left = $middle + 1;
            } else {
                $right = $middle;
            }
        }

        $candidate = $left - 1;

        return isset($coveredRanges[$candidate]) && $coveredRanges[$candidate][1] >= $span[1];
    }
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Normalizer;

use VergilLai\SensitiveText\Exception\NormalizationException;

final readonly class NormalizedText
{
    /**
     * @param list<string>     $characters
     * @param list<SourceSpan> $offsetMap
     * @param list<int>        $originalByteOffsets
     */
    public function __construct(
        public string $original,
        public string $normalized,
        public array $characters,
        public array $offsetMap,
        public array $originalByteOffsets,
    ) {}

    public function span(int $start, int $end): SourceSpan
    {
        if ($start < 0 || $end <= $start || $end > count($this->offsetMap)) {
            throw new NormalizationException('Invalid normalized interval');
        }

        $spanStart = $this->offsetMap[$start]->start;
        $spanEnd = $this->offsetMap[$start]->end;
        for ($index = $start + 1; $index < $end; ++$index) {
            $spanStart = min($spanStart, $this->offsetMap[$index]->start);
            $spanEnd = max($spanEnd, $this->offsetMap[$index]->end);
        }

        return new SourceSpan($spanStart, $spanEnd);
    }

    public function sliceOriginal(SourceSpan $span): string
    {
        if ($span->start < 0 || $span->end <= $span->start || $span->end >= count($this->originalByteOffsets)) {
            throw new NormalizationException('Invalid original interval');
        }

        return substr(
            $this->original,
            $this->originalByteOffsets[$span->start],
            $this->originalByteOffsets[$span->end] - $this->originalByteOffsets[$span->start],
        );
    }

    public function normalizedRangeForOriginal(int $start, int $end): string
    {
        if ($start < 0 || $end <= $start || $end >= count($this->originalByteOffsets)) {
            throw new NormalizationException('Invalid original interval');
        }

        $left = 0;
        $right = count($this->offsetMap);
        while ($left < $right) {
            $mid = intdiv($left + $right, 2);
            if ($this->offsetMap[$mid]->end <= $start) {
                $left = $mid + 1;
            } else {
                $right = $mid;
            }
        }
        $rangeStart = $left;

        $right = count($this->offsetMap);
        while ($left < $right) {
            $mid = intdiv($left + $right, 2);
            if ($this->offsetMap[$mid]->start < $end) {
                $left = $mid + 1;
            } else {
                $right = $mid;
            }
        }

        return implode('', array_slice($this->characters, $rangeStart, $left - $rangeStart));
    }
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Matcher;

use VergilLai\SensitiveText\Contracts\MatcherInterface;
use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Exception\InvalidRuleException;
use VergilLai\SensitiveText\Normalizer\NormalizedText;
use VergilLai\SensitiveText\Normalizer\SourceSpan;
use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Rules\RegexRule;
use VergilLai\SensitiveText\Rules\RegexTarget;

final readonly class RegexMatcher implements MatcherInterface
{
    /** @param list<RegexRule> $rules */
    public function __construct(private array $rules) {}

    /** @return list<MatchResult> */
    public function match(NormalizedText $text, CompiledDictionary $dictionary): array
    {
        $originalByteToPoint = null;
        $normalizedByteToPoint = null;
        $matches = [];

        foreach ($this->rules as $rule) {
            if (RegexTarget::Normalized === $rule->target) {
                $subject = $text->normalized;
                $normalizedByteToPoint ??= $this->normalizedByteToPoint($text);
                $byteToPoint = $normalizedByteToPoint;
            } else {
                $subject = $text->original;
                $originalByteToPoint ??= $this->originalByteToPoint($text);
                $byteToPoint = $originalByteToPoint;
            }

            $found = [];
            $count = @preg_match_all($rule->pattern, $subject, $found, PREG_OFFSET_CAPTURE);
            if (false === $count) {
                throw new InvalidRuleException($rule->id . ': ' . preg_last_error_msg());
            }

            /** @var list<array{0: string, 1: int}> $fullMatches */
            $fullMatches = $found[0];
            foreach ($fullMatches as [$value, $byteOffset]) {
                if ('' === $value) {
                    continue;
                }

                $endByteOffset = $byteOffset + strlen($value);
                if (!isset($byteToPoint[$byteOffset], $byteToPoint[$endByteOffset])) {
                    throw new InvalidRuleException($rule->id . ': regex returned an invalid Unicode offset');
                }

                $start = $byteToPoint[$byteOffset];
                $end = $byteToPoint[$endByteOffset];
                $span = RegexTarget::Normalized === $rule->target
                    ? $text->span($start, $end)
                    : new SourceSpan($start, $end);
                $normalizedTerm = RegexTarget::Normalized === $rule->target
                    ? $value
                    : $text->normalizedRangeForOriginal($span->start, $span->end);

                $matches[] = new MatchResult(
                    $rule->id,
                    $normalizedTerm,
                    $text->sliceOriginal($span),
                    $rule->category,
                    $rule->severity,
                    $rule->action,
                    $span->start,
                    $span->end,
                    'regex',
                    $rule->metadata,
                );
            }
        }

        return $matches;
    }

    /** @return array<int, int> */
    private function originalByteToPoint(NormalizedText $text): array
    {
        $map = [];
        foreach ($text->originalByteOffsets as $pointOffset => $byteOffset) {
            $map[$byteOffset] = $pointOffset;
        }

        return $map;
    }

    /** @return array<int, int> */
    private function normalizedByteToPoint(NormalizedText $text): array
    {
        $map = [0 => 0];
        $byteOffset = 0;
        foreach ($text->characters as $pointOffset => $character) {
            $byteOffset += strlen($character);
            $map[$byteOffset] = $pointOffset + 1;
        }

        return $map;
    }
}

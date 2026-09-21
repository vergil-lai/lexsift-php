<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Matcher;

use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\SensitiveDictionary;
use VergilLai\SensitiveText\Dictionary\SensitiveTerm;
use VergilLai\SensitiveText\Exception\DictionaryCompileException;
use VergilLai\SensitiveText\Normalizer\NormalizedText;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Result\MatchResult;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\WhitelistMode;
use VergilLai\SensitiveText\Rules\WhitelistRule;

final class WhitelistMatcher
{
    private readonly CompiledDictionary $phraseDictionary;

    /** @var array<string, true> */
    private readonly array $exactTerms;

    /**
     * @param list<WhitelistRule> $rules
     */
    public function __construct(
        TextNormalizer $normalizer,
        array $rules,
    ) {
        $phraseTerms = [];
        $exactTerms = [];
        foreach ($rules as $rule) {
            if (WhitelistMode::Phrase === $rule->mode) {
                $phraseTerms[] = new SensitiveTerm(
                    $rule->text,
                    category: 'whitelist',
                    action: Action::Allow,
                );

                continue;
            }

            $normalizedRule = $normalizer->normalize($rule->text)->normalized;
            if ('' === $normalizedRule) {
                throw new DictionaryCompileException('Enabled terms must not normalize to an empty string.');
            }

            $exactTerms[$normalizedRule] = true;
        }

        $this->exactTerms = $exactTerms;
        $this->phraseDictionary = (new DictionaryCompiler($normalizer))->compile(
            new SensitiveDictionary('whitelist', $phraseTerms),
        );
    }

    /**
     * @param list<MatchResult> $matches
     *
     * @return list<MatchResult>
     */
    public function filter(NormalizedText $text, array $matches): array
    {
        $phraseMatches = (new AhoCorasickMatcher())->match($text, $this->phraseDictionary);
        usort($phraseMatches, static fn(MatchResult $left, MatchResult $right): int => [
            $left->start,
            $left->end,
        ] <=> [
            $right->start,
            $right->end,
        ]);

        $phraseStarts = [];
        $prefixMaxEnds = [];
        $maxEnd = -1;
        foreach ($phraseMatches as $phraseMatch) {
            $phraseStarts[] = $phraseMatch->start;
            $maxEnd = max($maxEnd, $phraseMatch->end);
            $prefixMaxEnds[] = $maxEnd;
        }

        return array_values(array_filter(
            $matches,
            function (MatchResult $match) use ($text, $phraseStarts, $prefixMaxEnds): bool {
                $normalizedMatch = $text->normalizedRangeForOriginal($match->start, $match->end);
                if (isset($this->exactTerms[$normalizedMatch])) {
                    return false;
                }

                $left = 0;
                $right = count($phraseStarts);
                while ($left < $right) {
                    $mid = intdiv($left + $right, 2);
                    if ($phraseStarts[$mid] <= $match->start) {
                        $left = $mid + 1;
                    } else {
                        $right = $mid;
                    }
                }

                if (0 === $left) {
                    return true;
                }

                $prefixIndex = $left - 1;

                return isset($prefixMaxEnds[$prefixIndex])
                    && $prefixMaxEnds[$prefixIndex] < $match->end;
            },
        ));
    }
}

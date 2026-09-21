<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Matcher;

use VergilLai\SensitiveText\Contracts\MatcherInterface;
use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Normalizer\NormalizedText;
use VergilLai\SensitiveText\Result\MatchResult;

final class AhoCorasickMatcher implements MatcherInterface
{
    /** @return list<MatchResult> */
    public function match(NormalizedText $text, CompiledDictionary $dictionary): array
    {
        $state = 0;
        $matches = [];

        foreach ($text->characters as $index => $character) {
            $key = 'u:' . $character;
            while (0 !== $state && !isset($dictionary->transitions[$state][$key])) {
                $state = $dictionary->failures[$state] ?? 0;
            }

            $state = $dictionary->transitions[$state][$key] ?? 0;
            for ($node = $state; null !== $node; $node = $dictionary->outputLinks[$node]) {
                foreach ($dictionary->outputs[$node] as $termId) {
                    $span = $text->span(
                        $index + 1 - $dictionary->termLengths[$termId],
                        $index + 1,
                    );
                    $term = $dictionary->terms[$termId];
                    $matches[] = new MatchResult(
                        $term->term,
                        $dictionary->normalizedTerms[$termId],
                        $text->sliceOriginal($span),
                        $term->category,
                        $term->severity,
                        $term->action,
                        $span->start,
                        $span->end,
                        'aho_corasick',
                        $term->metadata,
                    );
                }
            }
        }

        return $matches;
    }
}

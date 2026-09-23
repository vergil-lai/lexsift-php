<?php

declare(strict_types=1);

namespace VergilLai\LexSift;

use VergilLai\LexSift\Dictionary\CompiledDictionary;
use VergilLai\LexSift\Dictionary\DictionaryCompiler;
use VergilLai\LexSift\Matcher\AhoCorasickMatcher;
use VergilLai\LexSift\Normalizer\TextNormalizer;

/** 与 LexSift 扩展共享参数及返回值约定的进程内匹配器。 */
final class Matcher
{
    private TextNormalizer $normalizer;
    private CompiledDictionary $dictionary;
    private AhoCorasickMatcher $matcher;
    private CompiledDictionary $whitelist;

    /**
     * @param array<array-key, string> $terms
     * @param array<array-key, string> $whitelist
     * @param array<string, bool> $options
     */
    public function __construct(array $terms, array $whitelist = [], array $options = [])
    {
        $normalizer = new TextNormalizer($options);
        $compiler = new DictionaryCompiler($normalizer);
        $dictionary = $compiler->compile($terms);
        $compiledWhitelist = $compiler->compile($whitelist, 'whitelist');
        // 所有输入编译成功后才更新状态，包括显式再次调用构造方法。
        $this->normalizer = $normalizer;
        $this->dictionary = $dictionary;
        $this->matcher = new AhoCorasickMatcher();
        $this->whitelist = $compiledWhitelist;
    }

    public function contains(string $text): bool
    {
        if ([] === $this->whitelist->terms) {
            return $this->matcher->containsCharacters($this->normalizer->characters($text), $this->dictionary);
        }
        $normalized = $this->normalizer->normalize($text);

        return $this->matcher->contains($normalized, $this->dictionary, $this->matcher->ranges($normalized, $this->whitelist));
    }

    /** @return list<array{term: string, text: string, start: int, end: int}> */
    public function scan(string $text): array
    {
        $normalized = $this->normalizer->normalize($text);
        $matches = $this->matcher->match($normalized, $this->dictionary, $this->matcher->ranges($normalized, $this->whitelist));
        $order = array_flip($this->dictionary->terms);
        usort($matches, static fn(array $a, array $b): int
            => [$a['start'], -$a['end'], $order[$a['term']]] <=> [$b['start'], -$b['end'], $order[$b['term']]]);

        return $matches;
    }

    public function mask(string $text, string $replacement = '*'): string
    {
        if (!mb_check_encoding($replacement, 'UTF-8')) {
            throw new \ValueError('replacement must contain valid UTF-8');
        }
        $ranges = [];
        foreach ($this->scan($text) as $match) {
            $last = array_key_last($ranges);
            if (null !== $last && $match['start'] <= $ranges[$last][1]) {
                $ranges[$last][1] = max($ranges[$last][1], $match['end']);
            } else {
                $ranges[] = [$match['start'], $match['end']];
            }
        }
        $cursor = 0;
        $result = '';
        foreach ($ranges as [$start, $end]) {
            $result .= substr($text, $cursor, $start - $cursor) . $replacement;
            $cursor = $end;
        }

        return $result . substr($text, $cursor);
    }

    /** @param array<array-key, string> $terms */
    public function replaceTerms(array $terms): void
    {
        $this->dictionary = (new DictionaryCompiler($this->normalizer))->compile($terms);
    }

    /** @param array<array-key, string> $whitelist */
    public function replaceWhitelist(array $whitelist): void
    {
        $this->whitelist = (new DictionaryCompiler($this->normalizer))->compile($whitelist, 'whitelist');
    }
}

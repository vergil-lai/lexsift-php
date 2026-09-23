<?php

declare(strict_types=1);

namespace VergilLai\LexSift\Normalizer;

use Normalizer;

/**
 * 对 UTF-8 文本执行可配置的 Unicode 归一化，并保留原文区间映射。
 *
 * @internal
 */
final class TextNormalizer
{
    /** @var array<string, bool> */
    private readonly array $options;

    /**
     * @param array<array-key, mixed> $options 词条和输入文本共享六个布尔选项
     * @param bool $asciiFastPath 仅供内部测试和基准关闭快速路径
     */
    public function __construct(array $options = [], private readonly bool $asciiFastPath = true)
    {
        $defaults = [
            'unicode_nfkc' => true,
            'lowercase' => true,
            'remove_whitespace' => true,
            'remove_punctuation' => false,
            'remove_symbols' => false,
            'remove_emoji' => true,
        ];
        foreach ($options as $key => $value) {
            if (!is_string($key) || !mb_check_encoding($key, 'UTF-8')) {
                throw new \ValueError('options keys must be valid UTF-8 strings');
            }
            if (!is_bool($value)) {
                throw new \TypeError("options[{$key}] must be of type bool");
            }
            if (!array_key_exists($key, $defaults)) {
                throw new \ValueError("options contains unknown key {$key}");
            }
            $defaults[$key] = $value;
        }

        $this->options = $defaults;
    }

    /**
     * 归一化文本并构建原始码点、字节偏移和来源区间映射。
     *
     * @throws \ValueError 输入不是有效 UTF-8 或 ICU/PCRE 处理失败
     */
    public function normalize(string $text): NormalizedText
    {
        $this->assertValidUtf8($text);

        $byteOffsets = mb_check_encoding($text, 'ASCII')
            ? range(0, strlen($text))
            : $this->byteOffsets($text);

        $characters = [];
        $sourceStarts = [];
        $sourceEnds = [];
        foreach ($this->entries($text) as [$character, $sourceStart, $sourceEnd]) {
            $characters[] = $character;
            $sourceStarts[] = $sourceStart;
            $sourceEnds[] = $sourceEnd;
        }

        return new NormalizedText(
            $text,
            implode('', $characters),
            $characters,
            $sourceStarts,
            $sourceEnds,
            $byteOffsets,
        );
    }

    /**
     * 只返回归一化字符串，不创建来源映射。
     *
     * @throws \ValueError 输入不是有效 UTF-8 或 ICU/PCRE 处理失败
     */
    public function normalizeString(string $text): string
    {
        $this->assertValidUtf8($text);

        $normalized = '';
        foreach ($this->entries($text) as [$character]) {
            $normalized .= $character;
        }

        return $normalized;
    }

    /**
     * 流式产生归一化字符，不创建来源映射或完整字符列表。
     *
     * @return \Generator<int, string>
     */
    public function characters(string $text): \Generator
    {
        $this->assertValidUtf8($text);

        foreach ($this->entries($text) as [$character]) {
            yield $character;
        }
    }

    /**
     * 逐个产生归一化字符及其原文区间。
     *
     * @return \Generator<int, array{0: string, 1: int, 2: int}>
     */
    private function entries(string $text): \Generator
    {
        if ($this->asciiFastPath && mb_check_encoding($text, 'ASCII')) {
            $value = $this->options['lowercase'] ? strtolower($text) : $text;
            $length = strlen($value);
            for ($index = 0; $index < $length; ++$index) {
                $character = $value[$index];
                if (!$this->shouldRemove($character, false)) {
                    // CRLF 是一个 grapheme；保留空白时与 Unicode 路径共享来源范围。
                    $start = "\n" === $character && $index > 0 && "\r" === $value[$index - 1] ? $index - 1 : $index;
                    $end = "\r" === $character && $index + 1 < $length && "\n" === $value[$index + 1] ? $index + 2 : $index + 1;
                    yield [$character, $start, $end];
                }
            }

            return;
        }

        $entries = [];
        $byteOffset = 0;
        $sourceIndex = 0;
        $byteLength = strlen($text);
        while ($byteOffset < $byteLength) {
            $result = preg_match('/\G\X/u', $text, $matches, 0, $byteOffset);
            if (1 !== $result) {
                throw new \ValueError('Unicode matching failed');
            }

            $cluster = $matches[0];
            $clusterEnd = $sourceIndex + count($this->splitCharacters($cluster));
            if (!$this->options['remove_emoji'] || !$this->isEmojiCluster($cluster)) {
                $value = $this->options['unicode_nfkc']
                    ? $this->normalizeUnicode($cluster, Normalizer::FORM_KD, 'Unicode normalization failed')
                    : $cluster;
                foreach ($this->splitCharacters($value) as $character) {
                    $entries[] = [$character, $sourceIndex, $clusterEnd];
                }
            }

            $byteOffset += strlen($cluster);
            $sourceIndex = $clusterEnd;
        }

        if ($this->options['unicode_nfkc']) {
            $entries = $this->composeEntries($entries);
        }
        foreach ($entries as [$character, $start, $end]) {
            // 逐码点小写，避免整串小写对希腊 sigma 等应用上下文规则。
            $value = $this->options['lowercase'] ? mb_strtolower($character, 'UTF-8') : $character;
            foreach ($this->splitCharacters($value) as $point) {
                if (!$this->shouldRemove($point, false)) {
                    yield [$point, $start, $end];
                }
            }
        }
    }

    /**
     * 构建原始码点边界对应的字节偏移。
     *
     * @return list<int>
     */
    private function byteOffsets(string $text): array
    {
        $offsets = [0];
        $byteOffset = 0;
        foreach ($this->splitCharacters($text) as $point) {
            $byteOffset += strlen($point);
            $offsets[] = $byteOffset;
        }

        return $offsets;
    }

    /**
     * 全串规范重排与合成，同时合并每个字符的原始来源范围。
     *
     * @param list<array{string, int, int}> $entries NFKD 分解后的字符
     * @return list<array{string, int, int}>
     */
    private function composeEntries(array $entries): array
    {
        $ordered = [];
        $marks = [];
        foreach ($entries as $entry) {
            if (0 === \IntlChar::getCombiningClass($entry[0])) {
                usort($marks, static fn(array $a, array $b): int => \IntlChar::getCombiningClass($a[0]) <=> \IntlChar::getCombiningClass($b[0]));
                foreach ($marks as $mark) {
                    $ordered[] = $mark;
                }
                $marks = [];
                $ordered[] = $entry;
            } else {
                $marks[] = $entry;
            }
        }
        usort($marks, static fn(array $a, array $b): int => \IntlChar::getCombiningClass($a[0]) <=> \IntlChar::getCombiningClass($b[0]));
        foreach ($marks as $mark) {
            $ordered[] = $mark;
        }

        $output = [];
        $starter = null;
        $lastClass = null;
        foreach ($ordered as [$character, $start, $end]) {
            $class = \IntlChar::getCombiningClass($character);
            if (null !== $starter && (null === $lastClass || $lastClass < $class)) {
                $composed = $this->normalizeUnicode($output[$starter][0] . $character, Normalizer::FORM_C, 'Unicode composition failed');
                if (1 === mb_strlen($composed, 'UTF-8')) {
                    $output[$starter] = [$composed, min($output[$starter][1], $start), max($output[$starter][2], $end)];
                    continue;
                }
            }
            $output[] = [$character, $start, $end];
            if (0 === $class) {
                $starter = array_key_last($output);
                $lastClass = null;
            } elseif (null !== $starter) {
                $lastClass = $class;
            }
        }

        return $output;
    }

    /** 验证输入是有效 UTF-8。 */
    private function assertValidUtf8(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \ValueError('text must contain valid UTF-8');
        }
    }

    /**
     * 调用 ICU 归一化并把失败统一转换为领域异常。
     */
    private function normalizeUnicode(string $text, int $form, string $failureMessage): string
    {
        $normalized = Normalizer::normalize($text, $form);
        if (!is_string($normalized)) {
            throw new \ValueError($failureMessage);
        }

        return $normalized;
    }

    /**
     * 判断当前归一化字符是否应按配置移除。
     */
    private function shouldRemove(string $character, bool $emoji): bool
    {
        if ($this->options['remove_emoji'] && $emoji) {
            return true;
        }
        if ($this->options['remove_whitespace'] && (
            (1 === strlen($character) && str_contains(" \t\n\r\v\f", $character))
            || (1 !== strlen($character) && $this->matches('/^\s$/u', $character))
        )) {
            return true;
        }
        if ($this->options['remove_punctuation'] && $this->matches('/^\p{P}$/u', $character)) {
            return true;
        }
        if ($this->options['remove_symbols'] && $this->matches('/^\p{S}$/u', $character)) {
            return true;
        }

        return false;
    }

    /**
     * 判断整个 grapheme cluster 是否包含 emoji 表征字符。
     */
    private function isEmojiCluster(string $cluster): bool
    {
        return $this->matches(
            '/(?:\p{Extended_Pictographic}|\p{Emoji_Presentation}|\p{Emoji_Modifier}|\p{Regional_Indicator}|\x{FE0F}|\x{20E3})/u',
            $cluster,
        );
    }

    /**
     * 将 UTF-8 字符串拆成 Unicode 码点列表。
     *
     * @return list<string>
     */
    private function splitCharacters(string $text): array
    {
        return $this->matchAll('/./us', $text);
    }

    /**
     * 执行必须成功的全局 Unicode 匹配。
     *
     * @return list<string>
     */
    private function matchAll(string $pattern, string $text): array
    {
        $result = preg_match_all($pattern, $text, $matches);
        if (false === $result) {
            throw new \ValueError('Unicode matching failed');
        }

        /** @var list<string> $characters */
        $characters = $matches[0];

        return $characters;
    }

    /**
     * 执行必须成功的单次 Unicode 匹配。
     */
    private function matches(string $pattern, string $text): bool
    {
        $result = preg_match($pattern, $text);
        if (false === $result) {
            throw new \ValueError('Unicode matching failed');
        }

        return 1 === $result;
    }
}

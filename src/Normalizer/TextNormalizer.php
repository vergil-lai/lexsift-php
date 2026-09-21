<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Normalizer;

use IntlChar;
use Normalizer;
use VergilLai\SensitiveText\Exception\NormalizationException;

final class TextNormalizer
{
    public function __construct(private readonly NormalizerConfig $config = new NormalizerConfig()) {}

    public function normalize(string $text): NormalizedText
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new NormalizationException('Input is not valid UTF-8');
        }

        $originalPoints = $this->splitCharacters($text);
        $byteOffsets = [0];
        $byteOffset = 0;
        foreach ($originalPoints as $point) {
            $byteOffset += strlen($point);
            $byteOffsets[] = $byteOffset;
        }

        $atoms = $this->decompose($text);
        if ($this->config->unicodeNfkc) {
            $atoms = $this->compose($this->canonicalOrder($atoms));
        }

        $characters = [];
        $offsetMap = [];
        foreach ($atoms as $atom) {
            $value = $this->config->lowercase
                ? mb_strtolower($atom['char'], 'UTF-8')
                : $atom['char'];

            foreach ($this->splitCharacters($value) as $character) {
                if ($this->shouldRemove($character, $atom['emoji'])) {
                    continue;
                }

                $characters[] = $character;
                $offsetMap[] = $atom['span'];
            }
        }

        return new NormalizedText(
            $text,
            implode('', $characters),
            $characters,
            $offsetMap,
            $byteOffsets,
        );
    }

    /**
     * @return list<array{char: string, span: SourceSpan, ccc: int, ordinal: int, emoji: bool}>
     */
    private function decompose(string $text): array
    {
        $clusters = $this->matchAll('/\X/u', $text);
        $atoms = [];
        $sourceIndex = 0;
        $ordinal = 0;

        foreach ($clusters as $cluster) {
            $sourcePoints = $this->splitCharacters($cluster);
            $clusterEnd = $sourceIndex + count($sourcePoints);
            $isEmoji = $this->isEmojiCluster($cluster);

            if ($this->config->unicodeNfkc) {
                $decomposed = $this->normalizeUnicode(
                    $cluster,
                    Normalizer::FORM_KD,
                    'Unicode decomposition failed',
                );

                foreach ($this->splitCharacters($decomposed) as $character) {
                    $atoms[] = $this->atom(
                        $character,
                        new SourceSpan($sourceIndex, $clusterEnd),
                        $ordinal++,
                        $isEmoji,
                    );
                }
            } else {
                foreach ($sourcePoints as $pointIndex => $character) {
                    $atoms[] = $this->atom(
                        $character,
                        new SourceSpan($sourceIndex + $pointIndex, $sourceIndex + $pointIndex + 1),
                        $ordinal++,
                        $isEmoji,
                    );
                }
            }

            $sourceIndex = $clusterEnd;
        }

        return $atoms;
    }

    /**
     * @return array{char: string, span: SourceSpan, ccc: int, ordinal: int, emoji: bool}
     */
    private function atom(string $character, SourceSpan $span, int $ordinal, bool $emoji): array
    {
        $codePoint = mb_ord($character, 'UTF-8');

        return [
            'char' => $character,
            'span' => $span,
            'ccc' => IntlChar::getCombiningClass($codePoint),
            'ordinal' => $ordinal,
            'emoji' => $emoji,
        ];
    }

    /**
     * @param list<array{char: string, span: SourceSpan, ccc: int, ordinal: int, emoji: bool}> $atoms
     *
     * @return list<array{char: string, span: SourceSpan, ccc: int, ordinal: int, emoji: bool}>
     */
    private function canonicalOrder(array $atoms): array
    {
        $ordered = [];
        $atomCount = count($atoms);
        $index = 0;

        while ($index < $atomCount) {
            if (0 === $atoms[$index]['ccc']) {
                $ordered[] = $atoms[$index++];

                continue;
            }

            $end = $index;
            while ($end < $atomCount && 0 !== $atoms[$end]['ccc']) {
                ++$end;
            }

            $segment = array_slice($atoms, $index, $end - $index);
            usort($segment, static fn(array $left, array $right): int => [
                $left['ccc'],
                $left['ordinal'],
            ] <=> [
                $right['ccc'],
                $right['ordinal'],
            ]);

            foreach ($segment as $atom) {
                $ordered[] = $atom;
            }
            $index = $end;
        }

        return $ordered;
    }

    /**
     * @param list<array{char: string, span: SourceSpan, ccc: int, ordinal: int, emoji: bool}> $atoms
     *
     * @return list<array{char: string, span: SourceSpan, ccc: int, ordinal: int, emoji: bool}>
     */
    private function compose(array $atoms): array
    {
        $output = [];
        $starter = null;
        $lastClass = 0;

        foreach ($atoms as $atom) {
            $candidate = null;
            if (null !== $starter && (0 === $lastClass || $lastClass < $atom['ccc'])) {
                $candidate = $this->normalizeUnicode(
                    $output[$starter]['char'] . $atom['char'],
                    Normalizer::FORM_C,
                    'Unicode composition failed',
                );
            }

            if (null !== $candidate && 1 === mb_strlen($candidate, 'UTF-8')) {
                $starterAtom = $output[$starter];
                $output[$starter] = [
                    'char' => $candidate,
                    'span' => new SourceSpan(
                        min($starterAtom['span']->start, $atom['span']->start),
                        max($starterAtom['span']->end, $atom['span']->end),
                    ),
                    'ccc' => $starterAtom['ccc'],
                    'ordinal' => $starterAtom['ordinal'],
                    'emoji' => $starterAtom['emoji'] || $atom['emoji'],
                ];

                continue;
            }

            if (0 === $atom['ccc']) {
                $starter = count($output);
            }
            $output[] = $atom;
            $lastClass = $atom['ccc'];
        }

        return $output;
    }

    private function normalizeUnicode(string $text, int $form, string $failureMessage): string
    {
        $normalized = Normalizer::normalize($text, $form);
        if (!is_string($normalized)) {
            throw new NormalizationException($failureMessage);
        }

        return $normalized;
    }

    private function shouldRemove(string $character, bool $emoji): bool
    {
        if ($this->config->removeEmoji && $emoji) {
            return true;
        }
        if ($this->config->removeWhitespace && $this->matches('/^\s$/u', $character)) {
            return true;
        }
        if ($this->config->removePunctuation && $this->matches('/^\p{P}$/u', $character)) {
            return true;
        }
        if ($this->config->removeSymbols && $this->matches('/^\p{S}$/u', $character)) {
            return true;
        }

        return in_array($character, $this->config->removeCharacters, true);
    }

    private function isEmojiCluster(string $cluster): bool
    {
        return $this->matches(
            '/(?:\p{Extended_Pictographic}|\p{Emoji_Presentation}|\p{Emoji_Modifier}|\p{Regional_Indicator}|\x{FE0F}|\x{20E3})/u',
            $cluster,
        );
    }

    /**
     * @return list<string>
     */
    private function splitCharacters(string $text): array
    {
        return $this->matchAll('/./us', $text);
    }

    /**
     * @return list<string>
     */
    private function matchAll(string $pattern, string $text): array
    {
        $result = preg_match_all($pattern, $text, $matches);
        if (false === $result) {
            throw new NormalizationException('Unicode matching failed');
        }

        /** @var list<string> $characters */
        $characters = $matches[0];

        return $characters;
    }

    private function matches(string $pattern, string $text): bool
    {
        $result = preg_match($pattern, $text);
        if (false === $result) {
            throw new NormalizationException('Unicode matching failed');
        }

        return 1 === $result;
    }
}

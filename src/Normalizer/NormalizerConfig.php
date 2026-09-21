<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Normalizer;

final readonly class NormalizerConfig
{
    /**
     * @param list<string> $removeCharacters
     */
    public function __construct(
        public bool $unicodeNfkc = true,
        public bool $lowercase = true,
        public bool $removeWhitespace = true,
        public bool $removePunctuation = false,
        public bool $removeSymbols = false,
        public bool $removeEmoji = true,
        public array $removeCharacters = [],
    ) {}

    public static function aggressive(): self
    {
        return new self(removePunctuation: true, removeSymbols: true);
    }
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText;

use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\Rules\RegexRule;
use VergilLai\SensitiveText\Rules\WhitelistRule;

final readonly class SensitiveTextConfig
{
    /**
     * @param list<RegexRule>     $regexRules
     * @param list<WhitelistRule> $whitelistRules
     */
    public function __construct(
        public NormalizerConfig $normalizer = new NormalizerConfig(),
        public string $redisUrl = 'tcp://127.0.0.1:6379',
        public string $redisPrefix = 'sensitive_text:',
        public string $dictionaryKey = 'dictionary',
        public string $versionKey = 'dictionary:version',
        public float $versionCheckInterval = 5.0,
        public float $redisTimeout = 1.0,
        public string $maskCharacter = '*',
        public array $regexRules = [],
        public array $whitelistRules = [],
    ) {
        if (!is_finite($versionCheckInterval) || $versionCheckInterval < 0) {
            throw new InvalidConfigurationException('Version check interval must be finite and not negative.');
        }
        if (!is_finite($redisTimeout) || $redisTimeout <= 0) {
            throw new InvalidConfigurationException('Redis timeout must be finite and positive.');
        }
        if ('' === $dictionaryKey || '' === $versionKey) {
            throw new InvalidConfigurationException('Dictionary and version keys must not be empty.');
        }
        if ($dictionaryKey === $versionKey) {
            throw new InvalidConfigurationException('Dictionary and version keys must be different.');
        }
        if (!mb_check_encoding($maskCharacter, 'UTF-8') || mb_strlen($maskCharacter, 'UTF-8') > 1) {
            throw new InvalidConfigurationException('Mask must be empty or a single valid UTF-8 codepoint.');
        }
    }
}

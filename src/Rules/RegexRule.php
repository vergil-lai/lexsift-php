<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Rules;

use VergilLai\SensitiveText\Exception\InvalidRuleException;

/**
 * @phpstan-type MetadataScalar bool|float|int|string|null
 * @phpstan-type MetadataValue MetadataScalar|array<array-key, MetadataScalar>
 */
final readonly class RegexRule
{
    /**
     * @param array<string, MetadataValue> $metadata
     */
    public function __construct(
        public string $id,
        public string $pattern,
        public string $category = 'default',
        public Severity $severity = Severity::Medium,
        public Action $action = Action::Flag,
        public RegexTarget $target = RegexTarget::Original,
        public array $metadata = [],
    ) {
        $delimiter = $pattern[0] ?? '';
        if (!in_array($delimiter, ['/', '~', '#'], true)) {
            throw new InvalidRuleException($id . ': unsupported regex delimiter');
        }

        $closingDelimiter = strlen($pattern) - 1;
        while ($closingDelimiter > 0 && ctype_alpha($pattern[$closingDelimiter])) {
            --$closingDelimiter;
        }

        if ($closingDelimiter < 1 || $pattern[$closingDelimiter] !== $delimiter) {
            throw new InvalidRuleException($id . ': invalid regex format');
        }

        $modifiers = substr($pattern, $closingDelimiter + 1);
        if (!str_contains($modifiers, 'u')) {
            throw new InvalidRuleException($id . ': regex must use the u modifier');
        }

        if (false === @preg_match($pattern, '')) {
            throw new InvalidRuleException($id . ': ' . preg_last_error_msg());
        }
    }
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Result;

use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

/**
 * @phpstan-type MetadataScalar bool|float|int|string|null
 * @phpstan-type MetadataValue MetadataScalar|array<array-key, MetadataScalar>
 */
final readonly class MatchResult
{
    /**
     * @param array<string, MetadataValue> $metadata
     */
    public function __construct(
        public string $term,
        public string $normalizedTerm,
        public string $matchedText,
        public string $category,
        public Severity $severity,
        public Action $action,
        public int $start,
        public int $end,
        public string $matcher,
        public array $metadata = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Dictionary;

use InvalidArgumentException;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

/**
 * @phpstan-type MetadataScalar bool|float|int|string|null
 * @phpstan-type MetadataValue MetadataScalar|array<array-key, MetadataScalar>
 */
final readonly class SensitiveTerm
{
    /** @var array<string, MetadataValue> */
    public array $metadata;

    /**
     * @param array<string, MetadataValue> $metadata
     */
    public function __construct(
        public string $term,
        public string $category = 'default',
        public Severity $severity = Severity::Medium,
        public Action $action = Action::Flag,
        public bool $enabled = true,
        array $metadata = [],
    ) {
        if ('' === $category) {
            throw new InvalidArgumentException('Category must not be empty.');
        }

        foreach ($metadata as $value) {
            if (!$this->isValidMetadataValue($value)) {
                throw new InvalidArgumentException('Metadata must contain JSON-compatible values with at most one nested array.');
            }
        }

        $this->metadata = $metadata;
    }

    private function isValidMetadataValue(mixed $value): bool
    {
        if (is_scalar($value) || null === $value) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $nestedValue) {
            if (!is_scalar($nestedValue) && null !== $nestedValue) {
                return false;
            }
        }

        return true;
    }
}

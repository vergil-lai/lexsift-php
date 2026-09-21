<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Dictionary;

final readonly class CompiledDictionary
{
    /**
     * @param list<SensitiveTerm>     $terms
     * @param list<string>            $normalizedTerms
     * @param list<int>               $termLengths
     * @param list<array<string, int>> $transitions
     * @param list<int>               $failures
     * @param list<list<int>>         $outputs
     * @param list<int|null>          $outputLinks
     */
    public function __construct(
        public string $version,
        public array $terms,
        public array $normalizedTerms,
        public array $termLengths,
        public array $transitions,
        public array $failures,
        public array $outputs,
        public array $outputLinks,
        public float $normalizationSeconds,
        public float $compileSeconds,
        public int $estimatedMemoryBytes,
    ) {}
}

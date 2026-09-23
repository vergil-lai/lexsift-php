<?php

declare(strict_types=1);

namespace VergilLai\LexSift\Dictionary;

/**
 * 保存一次编译完成、可在多次扫描间复用的不可变词库快照。
 *
 * @internal
 */
final readonly class CompiledDictionary
{
    /**
     * @param list<string>            $terms
     * @param list<string>            $normalizedTerms
     * @param list<int>               $termLengths
     * @param list<array<string, int>> $transitions
     * @param list<int>               $failures
     * @param list<list<int>>         $outputs
     * @param list<int|null>          $outputLinks
     */
    public function __construct(
        public array $terms,
        public array $normalizedTerms,
        public array $termLengths,
        public array $transitions,
        public array $failures,
        public array $outputs,
        public array $outputLinks,
    ) {}
}

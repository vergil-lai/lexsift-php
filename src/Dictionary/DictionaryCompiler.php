<?php

declare(strict_types=1);

namespace VergilLai\LexSift\Dictionary;

use SplQueue;
use VergilLai\LexSift\Normalizer\TextNormalizer;

/**
 * 将原始词库归一化、去重并编译为 Aho-Corasick 快照。
 *
 * @internal
 */
final class DictionaryCompiler
{
    public function __construct(private readonly TextNormalizer $normalizer) {}

    /**
     * 编译字符串词库，并按归一化结果去重。
     *
     * @param array<array-key, mixed> $terms
     *
     * @throws \ValueError 词库格式或词条内容无效
     */
    public function compile(array $terms, string $parameter = 'terms'): CompiledDictionary
    {
        $originalTerms = [];
        $normalizedTerms = [];
        $termLengths = [];
        $seen = [];

        foreach (array_values($terms) as $index => $term) {
            if (!is_string($term)) {
                throw new \TypeError("{$parameter}[{$index}] must be of type string");
            }
            if ('' === $term) {
                throw new \ValueError("{$parameter} contains an empty entry at index {$index}");
            }
            if (!mb_check_encoding($term, 'UTF-8')) {
                throw new \ValueError("{$parameter}[{$index}] must contain valid UTF-8");
            }

            $normalizedTerm = $this->normalizer->normalizeString($term);
            if ('' === $normalizedTerm) {
                throw new \ValueError("{$parameter} contains an empty normalized entry at index {$index}");
            }
            if (isset($seen[$normalizedTerm])) {
                continue;
            }

            $seen[$normalizedTerm] = true;
            $originalTerms[] = $term;
            $normalizedTerms[] = $normalizedTerm;
            $termLengths[] = mb_strlen($normalizedTerm, 'UTF-8');
        }

        $transitions = [[]];
        $failures = [0];
        $outputs = [[]];
        $outputLinks = [null];

        // 先构建 trie；输出表只记录在当前节点结束的词条。
        foreach ($normalizedTerms as $termId => $term) {
            $state = 0;
            foreach (mb_str_split($term, 1, 'UTF-8') as $character) {
                if (!isset($transitions[$state][$character])) {
                    $next = count($transitions);
                    $transitions[$state][$character] = $next;
                    $transitions[] = [];
                    $failures[] = 0;
                    $outputs[] = [];
                    $outputLinks[] = null;
                }

                $state = $transitions[$state][$character];
            }

            $outputs[$state][] = $termId;
        }

        /**
         * @var SplQueue<int> $queue
         */
        $queue = new SplQueue();
        foreach ($transitions[0] as $child) {
            $queue->enqueue($child);
        }

        // BFS 保证父节点的 failure 已完成；outputLinks 跳到最近的有输出后缀节点。
        while (!$queue->isEmpty()) {
            $parent = $queue->dequeue();

            foreach ($transitions[$parent] as $key => $child) {
                $queue->enqueue($child);
                $failure = $failures[$parent];

                while (0 !== $failure && !isset($transitions[$failure][$key])) {
                    $failure = $failures[$failure];
                }

                $failures[$child] = $transitions[$failure][$key] ?? 0;
                $target = $failures[$child];
                $outputLinks[$child] = [] !== $outputs[$target]
                    ? $target
                    : $outputLinks[$target];
            }
        }

        return new CompiledDictionary(
            $originalTerms,
            $normalizedTerms,
            $termLengths,
            $transitions,
            array_values($failures),
            $outputs,
            array_values($outputLinks),
        );
    }
}

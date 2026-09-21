<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Matcher;

use SplQueue;

final class AhoCorasickCompiler
{
    /**
     * @param list<string> $normalizedTerms
     *
     * @return array{
     *     transitions: list<array<string, int>>,
     *     failures: list<int>,
     *     outputs: list<list<int>>,
     *     outputLinks: list<int|null>
     * }
     */
    public function compile(array $normalizedTerms): array
    {
        $transitions = [[]];
        $failures = [0];
        $outputs = [[]];
        $outputLinks = [null];

        foreach ($normalizedTerms as $termId => $term) {
            $state = 0;
            foreach (mb_str_split($term, 1, 'UTF-8') as $character) {
                $key = 'u:' . $character;
                if (!isset($transitions[$state][$key])) {
                    $next = count($transitions);
                    $transitions[$state][$key] = $next;
                    $transitions[] = [];
                    $failures[] = 0;
                    $outputs[] = [];
                    $outputLinks[] = null;
                }

                $state = $transitions[$state][$key];
            }

            $outputs[$state][] = $termId;
        }

        /** @var SplQueue<int> $queue */
        $queue = new SplQueue();
        foreach ($transitions[0] as $child) {
            $queue->enqueue($child);
        }

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

        return [
            'transitions' => $transitions,
            'failures' => array_values($failures),
            'outputs' => $outputs,
            'outputLinks' => array_values($outputLinks),
        ];
    }
}

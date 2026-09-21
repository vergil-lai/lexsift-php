<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Dictionary;

use Throwable;
use VergilLai\SensitiveText\Exception\DictionaryCompileException;
use VergilLai\SensitiveText\Matcher\AhoCorasickCompiler;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;

final class DictionaryCompiler
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly AhoCorasickCompiler $automaton = new AhoCorasickCompiler(),
    ) {}

    public function compile(SensitiveDictionary $dictionary): CompiledDictionary
    {
        $normalizationStartedAt = hrtime(true);
        $terms = [];
        $normalizedTerms = [];
        $termLengths = [];
        $seen = [];

        try {
            foreach ($dictionary->terms as $term) {
                if (!$term->enabled) {
                    continue;
                }

                $normalizedTerm = $this->normalizer->normalize($term->term)->normalized;
                if ('' === $normalizedTerm) {
                    throw new DictionaryCompileException('Enabled terms must not normalize to an empty string.');
                }

                $identity = serialize([
                    $term->term,
                    $normalizedTerm,
                    $term->category,
                    $term->severity->value,
                    $term->action->value,
                    $this->sortMetadata($term->metadata),
                ]);
                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $terms[] = $term;
                $normalizedTerms[] = $normalizedTerm;
                $termLengths[] = mb_strlen($normalizedTerm, 'UTF-8');
            }
        } catch (DictionaryCompileException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DictionaryCompileException('Failed to normalize dictionary terms.', previous: $exception);
        }

        $normalizationSeconds = (hrtime(true) - $normalizationStartedAt) / 1_000_000_000;
        $compileStartedAt = hrtime(true);

        try {
            $tables = $this->automaton->compile($normalizedTerms);
        } catch (Throwable $exception) {
            throw new DictionaryCompileException('Failed to compile dictionary automaton.', previous: $exception);
        }

        $compileSeconds = (hrtime(true) - $compileStartedAt) / 1_000_000_000;
        $estimatedMemoryBytes = strlen(serialize([
            $terms,
            $normalizedTerms,
            $termLengths,
            $tables,
        ]));

        return new CompiledDictionary(
            $dictionary->version,
            $terms,
            $normalizedTerms,
            $termLengths,
            $tables['transitions'],
            $tables['failures'],
            $tables['outputs'],
            $tables['outputLinks'],
            $normalizationSeconds,
            $compileSeconds,
            $estimatedMemoryBytes,
        );
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<array-key, mixed>
     */
    private function sortMetadata(array $metadata): array
    {
        foreach ($metadata as &$value) {
            if (is_array($value)) {
                $value = $this->sortMetadata($value);
            }
        }
        unset($value);

        ksort($metadata);

        return $metadata;
    }
}

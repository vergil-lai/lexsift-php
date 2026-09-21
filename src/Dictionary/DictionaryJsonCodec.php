<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Dictionary;

use JsonException;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

final class DictionaryJsonCodec
{
    /** @param list<SensitiveTerm> $terms */
    public function encode(array $terms): string
    {
        $records = [];
        foreach ($terms as $term) {
            if ('' === $term->term) {
                throw new DictionaryException('Dictionary terms must not be empty.');
            }

            $records[] = [
                'term' => $term->term,
                'category' => $term->category,
                'severity' => $term->severity->value,
                'action' => $term->action->value,
                'enabled' => $term->enabled,
                'metadata' => $this->decodeMetadata($term->metadata),
            ];
        }

        try {
            return json_encode(['schema' => 1, 'terms' => $records], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DictionaryException('Unable to encode the dictionary payload.', previous: $exception);
        }
    }

    /** @return list<SensitiveTerm> */
    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $shape = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DictionaryException('Unable to decode the dictionary payload.', previous: $exception);
        }

        if (!$shape instanceof \stdClass
            || !property_exists($shape, 'terms')
            || !is_array($shape->terms)
            || !is_array($decoded)
            || 2 !== count($decoded)
            || !array_key_exists('schema', $decoded)
            || !array_key_exists('terms', $decoded)
            || 1 !== $decoded['schema']
            || !is_array($decoded['terms'])
            || !array_is_list($decoded['terms'])) {
            throw new DictionaryException('Invalid dictionary payload schema.');
        }

        $terms = [];
        foreach ($decoded['terms'] as $record) {
            if (!is_array($record)
                || 6 !== count($record)
                || !array_key_exists('term', $record)
                || !array_key_exists('category', $record)
                || !array_key_exists('severity', $record)
                || !array_key_exists('action', $record)
                || !array_key_exists('enabled', $record)
                || !array_key_exists('metadata', $record)) {
                throw new DictionaryException('Invalid dictionary term schema.');
            }

            $term = $record['term'];
            $category = $record['category'];
            $severityValue = $record['severity'];
            $actionValue = $record['action'];
            $enabled = $record['enabled'];
            $metadata = $record['metadata'];

            if (!is_string($term) || '' === $term
                || !is_string($category) || '' === $category
                || !is_int($severityValue)
                || !is_string($actionValue)
                || !is_bool($enabled)
                || !is_array($metadata)) {
                throw new DictionaryException('Invalid dictionary term field type.');
            }

            $severity = Severity::tryFrom($severityValue);
            $action = Action::tryFrom($actionValue);
            if (null === $severity || null === $action) {
                throw new DictionaryException('Invalid dictionary term enum value.');
            }

            $terms[] = new SensitiveTerm(
                $term,
                $category,
                $severity,
                $action,
                $enabled,
                $this->decodeMetadata($metadata),
            );
        }

        return $terms;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, bool|float|int|string|null|array<array-key, bool|float|int|string|null>>
     */
    private function decodeMetadata(array $metadata): array
    {
        $decoded = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                throw new DictionaryException('Dictionary metadata keys must be strings.');
            }

            if (is_scalar($value) || null === $value) {
                $decoded[$key] = $value;

                continue;
            }

            if (!is_array($value)) {
                throw new DictionaryException('Invalid dictionary metadata value.');
            }

            $nested = [];
            foreach ($value as $nestedKey => $nestedValue) {
                if (!is_scalar($nestedValue) && null !== $nestedValue) {
                    throw new DictionaryException('Dictionary metadata must not be nested more than once.');
                }

                $nested[$nestedKey] = $nestedValue;
            }
            $decoded[$key] = $nested;
        }

        return $decoded;
    }
}

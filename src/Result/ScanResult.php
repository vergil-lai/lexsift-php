<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Result;

use InvalidArgumentException;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\Severity;

final readonly class ScanResult
{
    /** @var list<MatchResult> */
    private array $matches;

    /** @param list<MatchResult> $matches */
    public function __construct(
        public string $original,
        array $matches,
        private string $defaultMask = '*',
    ) {
        $this->validateMask($defaultMask);
        $originalLength = mb_strlen($original, 'UTF-8');
        foreach ($matches as $match) {
            if ($match->start < 0 || $match->end <= $match->start || $match->end > $originalLength) {
                throw new InvalidArgumentException('Match range must be within the original text.');
            }
            if ($match->matchedText !== mb_substr(
                $original,
                $match->start,
                $match->end - $match->start,
                'UTF-8',
            )) {
                throw new InvalidArgumentException('Matched text must equal the original text slice.');
            }
        }

        usort($matches, static fn(MatchResult $left, MatchResult $right): int => [
            $left->start,
            $left->end,
            $left->matcher,
            $left->term,
        ] <=> [
            $right->start,
            $right->end,
            $right->matcher,
            $right->term,
        ]);

        $unique = [];
        $seen = [];
        foreach ($matches as $match) {
            $identity = $this->identity($match);
            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $unique[] = $match;
        }

        $this->matches = $unique;
    }

    /** @return list<MatchResult> */
    public function matches(): array
    {
        return $this->matches;
    }

    public function matched(): bool
    {
        return [] !== $this->matches;
    }

    public function count(): int
    {
        return count($this->matches);
    }

    public function highestSeverity(): ?Severity
    {
        $highest = null;
        foreach ($this->matches as $match) {
            if (null === $highest || $match->severity->value > $highest->value) {
                $highest = $match->severity;
            }
        }

        return $highest;
    }

    public function recommendedAction(): Action
    {
        $recommended = Action::Allow;
        foreach ($this->matches as $match) {
            if ($match->action->rank() > $recommended->rank()) {
                $recommended = $match->action;
            }
        }

        return $recommended;
    }

    public function shouldBlock(): bool
    {
        return Action::Block === $this->recommendedAction();
    }

    public function shouldReview(): bool
    {
        return Action::Review === $this->recommendedAction();
    }

    public function mask(?string $mask = null): string
    {
        $mask ??= $this->defaultMask;
        $this->validateMask($mask);

        $ranges = [];
        foreach ($this->matches as $match) {
            $last = array_key_last($ranges);
            if (null !== $last && $match->start <= $ranges[$last][1]) {
                $ranges[$last][1] = max($ranges[$last][1], $match->end);
            } else {
                $ranges[] = [$match->start, $match->end];
            }
        }

        $cursor = 0;
        $result = '';
        foreach ($ranges as [$start, $end]) {
            $result .= mb_substr($this->original, $cursor, $start - $cursor, 'UTF-8');
            $result .= str_repeat($mask, $end - $start);
            $cursor = $end;
        }

        return $result . mb_substr($this->original, $cursor, null, 'UTF-8');
    }

    private function validateMask(string $mask): void
    {
        if (!mb_check_encoding($mask, 'UTF-8') || mb_strlen($mask, 'UTF-8') > 1) {
            throw new InvalidArgumentException('Mask must be empty or a single valid UTF-8 codepoint.');
        }
    }

    private function identity(MatchResult $match): string
    {
        return serialize([
            $match->term,
            $match->normalizedTerm,
            $match->matchedText,
            $match->category,
            $match->severity->value,
            $match->action->value,
            $match->start,
            $match->end,
            $match->matcher,
            $this->sortMetadata($match->metadata),
        ]);
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

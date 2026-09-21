<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText;

use VergilLai\SensitiveText\Contracts\ClockInterface;
use VergilLai\SensitiveText\Contracts\DictionaryRepositoryInterface;
use VergilLai\SensitiveText\Contracts\MatcherInterface;
use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Matcher\WhitelistMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Result\ScannerStats;
use VergilLai\SensitiveText\Result\ScanResult;
use VergilLai\SensitiveText\Support\SystemClock;

final class SensitiveText
{
    private readonly DictionaryCompiler $compiler;

    private readonly WhitelistMatcher $whitelist;

    private readonly ClockInterface $clock;

    private ?CompiledDictionary $compiled = null;

    private bool $refreshing = false;

    private ?float $lastChecked = null;

    private ?\DateTimeImmutable $lastReloadAt = null;

    private ?\DateTimeImmutable $versionLastCheckedAt = null;

    private ?string $lastReloadError = null;

    /**
     * @param list<MatcherInterface> $matchers
     */
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly DictionaryRepositoryInterface $repository,
        private readonly array $matchers,
        ?DictionaryCompiler $compiler = null,
        ?WhitelistMatcher $whitelist = null,
        ?ClockInterface $clock = null,
        private readonly float $versionCheckInterval = 5.0,
    ) {
        if ([] === $matchers) {
            throw new InvalidConfigurationException('At least one matcher is required.');
        }
        if ($versionCheckInterval < 0) {
            throw new InvalidConfigurationException('Version check interval must not be negative.');
        }

        $this->compiler = $compiler ?? new DictionaryCompiler($normalizer);
        $this->whitelist = $whitelist ?? new WhitelistMatcher($normalizer, []);
        $this->clock = $clock ?? new SystemClock();
    }

    public function scan(string $text): ScanResult
    {
        $dictionary = $this->dictionaryForScan();
        $normalized = $this->normalizer->normalize($text);
        $matches = [];
        foreach ($this->matchers as $matcher) {
            array_push($matches, ...$matcher->match($normalized, $dictionary));
        }

        return new ScanResult($text, $this->whitelist->filter($normalized, $matches));
    }

    public function reload(): void
    {
        if ($this->refreshing) {
            throw new DictionaryException('Dictionary refresh is already in progress.');
        }

        $this->startCheck();
        $this->refreshing = true;
        try {
            $this->replaceDictionary();
        } catch (DictionaryException $exception) {
            $this->recordFailure($exception);

            throw $exception;
        } finally {
            $this->refreshing = false;
        }
    }

    public function invalidate(): void
    {
        $this->lastChecked = null;
    }

    public function stats(): ScannerStats
    {
        $compiled = $this->compiled;

        return new ScannerStats(
            $compiled?->version,
            null === $compiled ? 0 : count($compiled->terms),
            null === $compiled ? 0 : count($compiled->transitions),
            null === $compiled ? 0.0 : $compiled->compileSeconds,
            $this->lastReloadAt,
            null === $compiled ? 0 : $compiled->estimatedMemoryBytes,
            $this->versionLastCheckedAt,
            $this->lastReloadError,
        );
    }

    private function dictionaryForScan(): CompiledDictionary
    {
        if ($this->refreshing) {
            return $this->compiled
                ?? throw new DictionaryException('Dictionary snapshot is unavailable during initial refresh.');
        }

        $now = $this->clock->monotonic();
        if (null === $this->compiled && null !== $this->lastChecked
            && $now - $this->lastChecked < $this->versionCheckInterval) {
            throw new DictionaryException('Dictionary snapshot is unavailable after a recent refresh failure.');
        }

        if (null === $this->compiled
            || null === $this->lastChecked
            || $now - $this->lastChecked >= $this->versionCheckInterval) {
            $this->refreshAutomatically();
        }

        return $this->compiled
            ?? throw new DictionaryException('Dictionary snapshot is unavailable.');
    }

    private function refreshAutomatically(): void
    {
        $previous = $this->compiled;
        $this->startCheck();
        $this->refreshing = true;

        try {
            if (null === $previous || $this->repository->version() !== $previous->version) {
                $this->replaceDictionary();
            }
        } catch (DictionaryException $exception) {
            $this->recordFailure($exception);
            if (null === $previous) {
                throw $exception;
            }
        } finally {
            $this->refreshing = false;
        }
    }

    private function startCheck(): void
    {
        $this->lastChecked = $this->clock->monotonic();
        $this->versionLastCheckedAt = $this->clock->wallTime();
    }

    private function replaceDictionary(): void
    {
        $candidate = $this->compiler->compile($this->repository->load());
        $this->compiled = $candidate;
        $this->lastReloadAt = $this->clock->wallTime();
        $this->lastReloadError = null;
    }

    private function recordFailure(DictionaryException $exception): void
    {
        $this->lastReloadError = sprintf('%s: dictionary refresh failed', $exception::class);
    }
}

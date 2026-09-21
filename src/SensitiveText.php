<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText;

use VergilLai\SensitiveText\Contracts\ClockInterface;
use VergilLai\SensitiveText\Contracts\DictionaryRepositoryInterface;
use VergilLai\SensitiveText\Contracts\MatcherInterface;
use VergilLai\SensitiveText\Dictionary\CompiledDictionary;
use VergilLai\SensitiveText\Dictionary\DictionaryCompiler;
use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Exception\DictionaryException;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Matcher\RegexMatcher;
use VergilLai\SensitiveText\Matcher\WhitelistMatcher;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Redis\PhpRedisClientAdapter;
use VergilLai\SensitiveText\Result\ScannerStats;
use VergilLai\SensitiveText\Result\ScanResult;
use VergilLai\SensitiveText\Support\DefaultScanner;
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
        private readonly string $maskCharacter = '*',
    ) {
        if ([] === $matchers) {
            throw new InvalidConfigurationException('At least one matcher is required.');
        }
        if ($versionCheckInterval < 0) {
            throw new InvalidConfigurationException('Version check interval must not be negative.');
        }
        if (!mb_check_encoding($maskCharacter, 'UTF-8') || mb_strlen($maskCharacter, 'UTF-8') > 1) {
            throw new InvalidConfigurationException('Mask must be empty or a single valid UTF-8 codepoint.');
        }

        $this->compiler = $compiler ?? new DictionaryCompiler($normalizer);
        $this->whitelist = $whitelist ?? new WhitelistMatcher($normalizer, []);
        $this->clock = $clock ?? new SystemClock();
    }

    public static function instance(): self
    {
        return DefaultScanner::instance();
    }

    public static function fromConfig(SensitiveTextConfig $config): self
    {
        $normalizer = new TextNormalizer($config->normalizer);

        return new self(
            $normalizer,
            new RedisDictionaryRepository(
                PhpRedisClientAdapter::fromUrl($config->redisUrl, $config->redisTimeout),
                $config->redisPrefix,
                $config->dictionaryKey,
                $config->versionKey,
            ),
            [new AhoCorasickMatcher(), new RegexMatcher($config->regexRules)],
            whitelist: new WhitelistMatcher($normalizer, $config->whitelistRules),
            versionCheckInterval: $config->versionCheckInterval,
            maskCharacter: $config->maskCharacter,
        );
    }

    public function scan(string $text): ScanResult
    {
        $dictionary = $this->dictionaryForScan();
        $normalized = $this->normalizer->normalize($text);
        $matches = [];
        foreach ($this->matchers as $matcher) {
            array_push($matches, ...$matcher->match($normalized, $dictionary));
        }

        return new ScanResult($text, $this->whitelist->filter($normalized, $matches), $this->maskCharacter);
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

<?php

declare(strict_types=1);

namespace VergilLai\SensitiveText\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\ServiceProvider;
use VergilLai\SensitiveText\Dictionary\RedisDictionaryRepository;
use VergilLai\SensitiveText\Exception\InvalidConfigurationException;
use VergilLai\SensitiveText\Exception\InvalidRuleException;
use VergilLai\SensitiveText\Matcher\AhoCorasickMatcher;
use VergilLai\SensitiveText\Matcher\RegexMatcher;
use VergilLai\SensitiveText\Matcher\WhitelistMatcher;
use VergilLai\SensitiveText\Normalizer\NormalizerConfig;
use VergilLai\SensitiveText\Normalizer\TextNormalizer;
use VergilLai\SensitiveText\Rules\Action;
use VergilLai\SensitiveText\Rules\RegexRule;
use VergilLai\SensitiveText\Rules\RegexTarget;
use VergilLai\SensitiveText\Rules\Severity;
use VergilLai\SensitiveText\Rules\WhitelistMode;
use VergilLai\SensitiveText\Rules\WhitelistRule;
use VergilLai\SensitiveText\SensitiveText;
use VergilLai\SensitiveText\SensitiveTextConfig;

final class SensitiveTextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sensitive-text.php', 'sensitive-text');
        $this->app->singleton(SensitiveText::class, function (Application $app): SensitiveText {
            $config = $app->make(ConfigRepository::class)->get('sensitive-text');

            return $this->buildScanner($app, $this->arrayValue($config, 'sensitive-text'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/sensitive-text.php' => config_path('sensitive-text.php'),
        ], 'sensitive-text-config');
    }

    /** @param array<string, mixed> $config */
    private function buildScanner(Application $app, array $config): SensitiveText
    {
        $redisConfig = $this->arrayValue($config['redis'] ?? null, 'redis');
        $dictionaryConfig = $this->arrayValue($config['dictionary'] ?? null, 'dictionary');
        $normalizerConfig = $this->arrayValue($config['normalizer'] ?? null, 'normalizer');
        $reloadConfig = $this->arrayValue($config['reload'] ?? null, 'reload');
        $batchConfig = $this->arrayValue($config['batch'] ?? null, 'batch');

        if ('keep_last_good' !== $this->stringValue($reloadConfig['policy'] ?? null, 'reload.policy')) {
            throw new InvalidConfigurationException('Unsupported reload policy.');
        }
        if ('sync' !== $this->stringValue($batchConfig['driver'] ?? null, 'batch.driver')) {
            throw new InvalidConfigurationException('Unsupported batch driver.');
        }
        if (isset($redisConfig['driver']) && 'phpredis' !== $redisConfig['driver']) {
            throw new InvalidConfigurationException('Laravel Redis driver must be phpredis.');
        }

        $normalizerConfig = new NormalizerConfig(
            unicodeNfkc: $this->boolValue($normalizerConfig['unicode_nfkc'] ?? null, 'normalizer.unicode_nfkc'),
            lowercase: $this->boolValue($normalizerConfig['lowercase'] ?? null, 'normalizer.lowercase'),
            removeWhitespace: $this->boolValue(
                $normalizerConfig['remove_whitespace'] ?? null,
                'normalizer.remove_whitespace',
            ),
            removePunctuation: $this->boolValue(
                $normalizerConfig['remove_punctuation'] ?? null,
                'normalizer.remove_punctuation',
            ),
            removeSymbols: $this->boolValue(
                $normalizerConfig['remove_symbols'] ?? null,
                'normalizer.remove_symbols',
            ),
            removeEmoji: $this->boolValue($normalizerConfig['remove_emoji'] ?? null, 'normalizer.remove_emoji'),
            removeCharacters: $this->stringList(
                $normalizerConfig['remove_characters'] ?? null,
                'normalizer.remove_characters',
            ),
        );
        $validated = new SensitiveTextConfig(
            normalizer: $normalizerConfig,
            redisPrefix: $this->stringValue($redisConfig['prefix'] ?? null, 'redis.prefix'),
            dictionaryKey: $this->stringValue($dictionaryConfig['key'] ?? null, 'dictionary.key'),
            versionKey: $this->stringValue($dictionaryConfig['version_key'] ?? null, 'dictionary.version_key'),
            versionCheckInterval: $this->floatValue(
                $config['version_check_interval'] ?? null,
                'version_check_interval',
            ),
            maskCharacter: $this->stringValue($config['mask_character'] ?? null, 'mask_character'),
            regexRules: $this->regexRules($config['regex_rules'] ?? null),
            whitelistRules: $this->whitelistRules(
                $this->arrayValue($config['whitelist'] ?? null, 'whitelist')['rules'] ?? null,
            ),
        );
        $normalizer = new TextNormalizer($validated->normalizer);
        $manager = $app->make(RedisFactory::class);
        $connectionName = $this->stringValue($redisConfig['connection'] ?? null, 'redis.connection');
        $redis = LaravelRedisAdapter::lazy(static fn(): mixed => $manager->connection($connectionName));

        return new SensitiveText(
            $normalizer,
            new RedisDictionaryRepository(
                $redis,
                $validated->redisPrefix,
                $validated->dictionaryKey,
                $validated->versionKey,
            ),
            [new AhoCorasickMatcher(), new RegexMatcher($validated->regexRules)],
            whitelist: new WhitelistMatcher($normalizer, $validated->whitelistRules),
            versionCheckInterval: $validated->versionCheckInterval,
            maskCharacter: $validated->maskCharacter,
        );
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new InvalidConfigurationException("Configuration [{$path}] must be an array.");
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidConfigurationException("Configuration [{$path}] must use string keys.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function stringValue(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new InvalidConfigurationException("Configuration [{$path}] must be a string.");
        }

        return $value;
    }

    private function boolValue(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new InvalidConfigurationException("Configuration [{$path}] must be a boolean.");
        }

        return $value;
    }

    private function floatValue(mixed $value, string $path): float
    {
        if (!is_float($value) && !is_int($value)) {
            throw new InvalidConfigurationException("Configuration [{$path}] must be numeric.");
        }

        return (float) $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigurationException("Configuration [{$path}] must be a list of strings.");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidConfigurationException("Configuration [{$path}] must be a list of strings.");
            }
        }

        return $value;
    }

    /** @return list<RegexRule> */
    private function regexRules(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigurationException('Configuration [regex_rules] must be a list.');
        }

        $rules = [];
        foreach ($value as $rule) {
            $rule = $this->arrayValue($rule, 'regex_rules.*');
            $metadata = $this->metadata($rule['metadata'] ?? []);
            try {
                $rules[] = new RegexRule(
                    id: $this->stringValue($rule['id'] ?? null, 'regex_rules.*.id'),
                    pattern: $this->stringValue($rule['pattern'] ?? null, 'regex_rules.*.pattern'),
                    category: $this->stringValue($rule['category'] ?? 'default', 'regex_rules.*.category'),
                    severity: Severity::from($this->enumValue(
                        $rule['severity'] ?? Severity::Medium->value,
                        'regex_rules.*.severity',
                    )),
                    action: Action::from($this->enumValue(
                        $rule['action'] ?? Action::Flag->value,
                        'regex_rules.*.action',
                    )),
                    target: RegexTarget::from($this->enumValue(
                        $rule['target'] ?? RegexTarget::Original->value,
                        'regex_rules.*.target',
                    )),
                    metadata: $metadata,
                );
            } catch (InvalidRuleException $exception) {
                throw new InvalidConfigurationException(
                    'Configuration [regex_rules] contains an invalid rule.',
                    previous: $exception,
                );
            } catch (\TypeError|\ValueError $exception) {
                throw new InvalidConfigurationException('Configuration [regex_rules] contains an invalid enum value.', previous: $exception);
            }
        }

        return $rules;
    }

    /** @return list<WhitelistRule> */
    private function whitelistRules(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigurationException('Configuration [whitelist.rules] must be a list.');
        }

        $rules = [];
        foreach ($value as $rule) {
            $rule = $this->arrayValue($rule, 'whitelist.rules.*');
            try {
                $rules[] = new WhitelistRule(
                    $this->stringValue($rule['text'] ?? null, 'whitelist.rules.*.text'),
                    WhitelistMode::from($this->enumValue(
                        $rule['mode'] ?? WhitelistMode::Phrase->value,
                        'whitelist.rules.*.mode',
                    )),
                );
            } catch (\TypeError|\ValueError $exception) {
                throw new InvalidConfigurationException(
                    'Configuration [whitelist.rules] contains an invalid mode.',
                    previous: $exception,
                );
            }
        }

        return $rules;
    }

    private function enumValue(mixed $value, string $path): int|string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidConfigurationException("Configuration [{$path}] must be an enum scalar.");
        }

        return $value;
    }

    /**
     * @return array<string, array<array-key, bool|float|int|string|null>|bool|float|int|string|null>
     */
    private function metadata(mixed $value): array
    {
        $metadata = $this->arrayValue($value, 'regex_rules.*.metadata');
        $result = [];
        foreach ($metadata as $key => $item) {
            if (is_array($item)) {
                foreach ($item as $nested) {
                    if (!is_bool($nested) && !is_float($nested) && !is_int($nested)
                        && !is_string($nested) && null !== $nested) {
                        throw new InvalidConfigurationException('Regex rule metadata must contain scalar values.');
                    }
                }
                $result[$key] = $item;

                continue;
            }
            if (!is_bool($item) && !is_float($item) && !is_int($item) && !is_string($item) && null !== $item) {
                throw new InvalidConfigurationException('Regex rule metadata must contain scalar values.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
